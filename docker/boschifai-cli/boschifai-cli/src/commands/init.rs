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
}

#[derive(Deserialize)]
struct Category {
    name: String,
    target: String,
    files: Vec<String>,
}

pub fn run_init(yes: bool, force: bool) -> anyhow::Result<()> {
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

    if version_file.exists() && !force {
        eprintln!(
            "Boschifai is already installed in this project (.boschifai-version exists).\nUse --force to reinstall."
        );
        std::process::exit(1);
    }

    let total_files: usize = manifest.categories.iter().map(|c| c.files.len()).sum();
    println!("Boschifai {} — installing into {}", manifest.version, cwd.display());
    println!();
    for cat in &manifest.categories {
        println!("  {} → {} ({} files)", cat.name, cat.target, cat.files.len());
    }
    println!();
    println!("Total: {} files", total_files);

    if !yes {
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

    if force && version_file.exists() {
        remove_existing_files(&cwd, &manifest)?;
    }

    let mut copied = 0usize;
    for cat in &manifest.categories {
        let src_base = templates_dir.join(category_src_dir(&cat.name));
        let dst_base = cwd.join(&cat.target);

        for file in &cat.files {
            let src = src_base.join(file);
            let dst = dst_base.join(file);

            if let Some(parent) = dst.parent() {
                fs::create_dir_all(parent)?;
            }

            fs::copy(&src, &dst).map_err(|e| {
                anyhow::anyhow!("Failed to copy {} → {}: {}", src.display(), dst.display(), e)
            })?;
            copied += 1;
        }
    }

    let version_content = format!(
        "version: \"{}\"\ninstalled_at: \"{}\"\ncli_version: \"{}\"\n",
        manifest.version,
        Utc::now().to_rfc3339(),
        CLI_VERSION,
    );
    fs::write(&version_file, version_content)?;

    println!();
    println!("Installed {} files.", copied);
    println!("Open this repo in Claude Code and type / to see /boschifai-* commands.");

    Ok(())
}

fn resolve_templates_dir() -> anyhow::Result<PathBuf> {
    let home = std::env::var("HOME")
        .or_else(|_| std::env::var("USERPROFILE"))
        .map_err(|_| anyhow::anyhow!("Cannot determine home directory (HOME or USERPROFILE not set)"))?;
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
