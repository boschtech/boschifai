use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Path, PathBuf};

use chrono::Utc;
use serde::Deserialize;

const TEMPLATES_DIR: &str = ".boschifai/templates";
const VERSION_FILE: &str = ".boschifai-version";
const CLI_VERSION: &str = env!("CARGO_PKG_VERSION");

#[derive(Deserialize)]
struct TemplateManifest {
    version: String,
    categories: Vec<Category>,
    #[serde(default)]
    profiles: BTreeMap<String, Profile>,
    #[serde(default)]
    stacks: BTreeMap<String, Stack>,
}

#[derive(Deserialize)]
struct Category {
    name: String,
    target: String,
    files: Vec<String>,
}

/// A named subset of the templates — e.g. `pipeline`, only what the BoschifAI app's run
/// pipeline uses. Keyed by category name, so it reuses each category's source and target.
#[derive(Deserialize)]
struct Profile {
    #[serde(default)]
    description: String,
    include: BTreeMap<String, Vec<String>>,
}

/// Tech-stack-specific templates, added to a profile when the project looks like that stack.
#[derive(Deserialize)]
struct Stack {
    detect: Vec<DetectRule>,
    include: BTreeMap<String, Vec<String>>,
}

/// Matches when `file` exists in the project root and, if `contains` is non-empty, includes at
/// least one of those strings.
#[derive(Deserialize)]
struct DetectRule {
    file: String,
    #[serde(default)]
    contains: Vec<String>,
}

pub struct InitOptions {
    pub yes: bool,
    pub force: bool,
    /// None installs every template (the original behaviour).
    pub profile: Option<String>,
    /// Overrides stack detection when non-empty. Only used with a profile.
    pub stacks: Vec<String>,
}

/// What a run of `init` will install: for each category, the files to copy.
struct Selection {
    files: BTreeMap<String, Vec<String>>,
    profile: Option<String>,
    stacks: Vec<String>,
    stacks_detected: bool,
}

pub fn run_init(options: &InitOptions) -> anyhow::Result<()> {
    let templates_dir = resolve_templates_dir()?;

    let manifest_path = templates_dir.join("manifest.yaml");
    if !manifest_path.exists() {
        eprintln!(
            "Templates not found at {}.\nRun the QArrive installer first: ./install.sh (or install.ps1 on Windows).",
            templates_dir.display()
        );
        std::process::exit(1);
    }

    let manifest_text = fs::read_to_string(&manifest_path)?;
    let manifest: TemplateManifest = serde_yaml::from_str(&manifest_text)?;

    let cwd = std::env::current_dir()?;
    let version_file = cwd.join(VERSION_FILE);

    if version_file.exists() && !options.force {
        eprintln!(
            "Boschifai is already installed in this project (.boschifai-version exists).\nUse --force to reinstall."
        );
        std::process::exit(1);
    }

    let selection = select(&manifest, options, &cwd)?;

    let total_files: usize = selection.files.values().map(Vec::len).sum();
    println!(
        "Boschifai {} — installing into {}",
        manifest.version,
        cwd.display()
    );
    if let Some(name) = &selection.profile {
        let description = &manifest.profiles[name].description;
        println!(
            "Profile: {name}{}",
            if description.is_empty() {
                String::new()
            } else {
                format!(" — {description}")
            }
        );
        let how = if selection.stacks_detected {
            "detected"
        } else {
            "from --stack"
        };
        if selection.stacks.is_empty() {
            println!("Stacks ({how}): none — installing base skills only");
        } else {
            println!("Stacks ({how}): {}", selection.stacks.join(", "));
        }
    }
    println!();
    for cat in &manifest.categories {
        let count = selection.files.get(&cat.name).map_or(0, Vec::len);
        if count > 0 {
            println!("  {} → {} ({} files)", cat.name, cat.target, count);
        }
    }
    println!();
    println!("Total: {} files", total_files);

    if !options.yes {
        print!("Proceed? [Y/n] ");
        use std::io::{self, Write};
        io::stdout().flush()?;
        let mut answer = String::new();
        io::stdin().read_line(&mut answer)?;
        let answer = answer.trim().to_lowercase();
        if answer == "n" || answer == "no" {
            println!("Aborted.");
            return Ok(());
        }
    }

    if options.force && version_file.exists() {
        remove_existing_files(&cwd, &manifest)?;
    }

    let mut copied = 0usize;
    for cat in &manifest.categories {
        let Some(files) = selection.files.get(&cat.name) else {
            continue;
        };
        let src_base = templates_dir.join(category_src_dir(&cat.name));
        let dst_base = cwd.join(&cat.target);

        for file in files {
            let src = src_base.join(file);
            let dst = dst_base.join(file);

            if let Some(parent) = dst.parent() {
                fs::create_dir_all(parent)?;
            }

            fs::copy(&src, &dst).map_err(|e| {
                anyhow::anyhow!(
                    "Failed to copy {} → {}: {}",
                    src.display(),
                    dst.display(),
                    e
                )
            })?;
            copied += 1;
        }
    }

    let mut version_content = format!(
        "version: \"{}\"\ninstalled_at: \"{}\"\ncli_version: \"{}\"\n",
        manifest.version,
        Utc::now().to_rfc3339(),
        CLI_VERSION,
    );
    if let Some(name) = &selection.profile {
        version_content.push_str(&format!(
            "profile: \"{name}\"\nstacks: [{}]\n",
            selection
                .stacks
                .iter()
                .map(|s| format!("\"{s}\""))
                .collect::<Vec<_>>()
                .join(", ")
        ));
    }
    fs::write(&version_file, version_content)?;

    println!();
    println!("Installed {} files.", copied);
    println!("Open this repo in Claude Code and type / to see /boschifai-* commands.");

    Ok(())
}

/// Everything in the manifest without a profile; otherwise the profile's files plus those of
/// each stack (forced with --stack, or detected from the project), de-duplicated and kept in
/// manifest order. Every selected file must also be listed in its category — a profile can't
/// install something plain `init` wouldn't.
fn select(
    manifest: &TemplateManifest,
    options: &InitOptions,
    project: &Path,
) -> anyhow::Result<Selection> {
    let Some(profile_name) = &options.profile else {
        return Ok(Selection {
            files: manifest
                .categories
                .iter()
                .map(|c| (c.name.clone(), c.files.clone()))
                .collect(),
            profile: None,
            stacks: vec![],
            stacks_detected: false,
        });
    };

    let profile = manifest.profiles.get(profile_name).ok_or_else(|| {
        anyhow::anyhow!(
            "Unknown profile \"{profile_name}\". Available profiles: {}",
            join_keys(&manifest.profiles)
        )
    })?;

    let stacks_detected = options.stacks.is_empty();
    let stack_names: Vec<String> = if stacks_detected {
        manifest
            .stacks
            .iter()
            .filter(|(_, stack)| stack.detect.iter().any(|rule| rule.matches(project)))
            .map(|(name, _)| name.clone())
            .collect()
    } else {
        let mut names = Vec::new();
        for name in &options.stacks {
            let name = name.trim().to_string();
            if !manifest.stacks.contains_key(&name) {
                anyhow::bail!(
                    "Unknown stack \"{name}\". Available stacks: {}",
                    join_keys(&manifest.stacks)
                );
            }
            if !names.contains(&name) {
                names.push(name);
            }
        }
        names
    };

    let mut wanted: BTreeMap<&str, BTreeSet<&str>> = BTreeMap::new();
    let includes = std::iter::once(&profile.include).chain(
        stack_names
            .iter()
            .map(|name| &manifest.stacks[name].include),
    );
    for include in includes {
        for (category, files) in include {
            wanted
                .entry(category.as_str())
                .or_default()
                .extend(files.iter().map(String::as_str));
        }
    }

    let mut files = BTreeMap::new();
    for (category, names) in &wanted {
        let listed = manifest
            .categories
            .iter()
            .find(|c| c.name == *category)
            .ok_or_else(|| anyhow::anyhow!("Manifest error: unknown category \"{category}\""))?;
        if let Some(missing) = names.iter().find(|n| !listed.files.iter().any(|f| f == *n)) {
            anyhow::bail!(
                "Manifest error: \"{missing}\" is not listed under the {category} category"
            );
        }
        let ordered: Vec<String> = listed
            .files
            .iter()
            .filter(|f| names.contains(f.as_str()))
            .cloned()
            .collect();
        files.insert(category.to_string(), ordered);
    }

    Ok(Selection {
        files,
        profile: Some(profile_name.clone()),
        stacks: stack_names,
        stacks_detected,
    })
}

impl DetectRule {
    fn matches(&self, project: &Path) -> bool {
        let path = project.join(&self.file);
        if !path.is_file() {
            return false;
        }
        if self.contains.is_empty() {
            return true;
        }
        let content = fs::read_to_string(&path).unwrap_or_default();
        self.contains.iter().any(|needle| content.contains(needle))
    }
}

fn join_keys<V>(map: &BTreeMap<String, V>) -> String {
    map.keys().cloned().collect::<Vec<_>>().join(", ")
}

fn resolve_templates_dir() -> anyhow::Result<PathBuf> {
    let home = std::env::var("HOME")
        .or_else(|_| std::env::var("USERPROFILE"))
        .map_err(|_| {
            anyhow::anyhow!("Cannot determine home directory (HOME or USERPROFILE not set)")
        })?;
    Ok(PathBuf::from(home).join(TEMPLATES_DIR))
}

fn category_src_dir(name: &str) -> &str {
    match name {
        "claude_commands" => "claude/commands",
        "claude_skills" => "claude/skills",
        "github_prompts" => "github/prompts",
        _ => name,
    }
}

fn remove_existing_files(cwd: &Path, manifest: &TemplateManifest) -> anyhow::Result<()> {
    for cat in &manifest.categories {
        let dst_base = cwd.join(&cat.target);
        for file in &cat.files {
            let dst = dst_base.join(file);
            if dst.exists() {
                fs::remove_file(&dst)?;
            }
            // Remove parent dir if empty (e.g. skill subdirectory)
            if let Some(parent) = dst.parent() {
                if parent != dst_base && parent.exists() {
                    let _ = fs::remove_dir(parent); // silently ignore if not empty
                }
            }
        }
    }
    Ok(())
}
