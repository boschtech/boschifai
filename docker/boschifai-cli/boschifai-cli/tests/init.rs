//! `boschifai init` is the one command the BoschifAI pipeline runs on every job (see
//! app/Services/Sandbox/RunWorkspaceManager.php), so it's exercised end to end here against the
//! real templates in this repository.

mod common;

use common::*;
use std::fs;
use std::path::Path;
use tempfile::TempDir;

struct Env {
    home: TempDir,
    project: TempDir,
}

impl Env {
    /// A fake HOME holding a copy of the real templates, plus an empty project directory.
    fn with_templates() -> Self {
        let env = Self::empty();
        copy_dir(
            &templates_source(),
            &env.home.path().join(".boschifai/templates"),
        );
        env
    }

    fn empty() -> Self {
        Env {
            home: TempDir::new().unwrap(),
            project: TempDir::new().unwrap(),
        }
    }

    fn init(&self, args: &[&str]) -> std::process::Command {
        let mut cmd = boschifai();
        cmd.arg("init")
            .args(args)
            .env("HOME", self.home.path())
            .env_remove("USERPROFILE")
            .current_dir(self.project.path());
        cmd
    }

    fn path(&self, relative: &str) -> std::path::PathBuf {
        self.project.path().join(relative)
    }
}

fn manifest() -> serde_yaml::Value {
    serde_yaml::from_str(&fs::read_to_string(templates_source().join("manifest.yaml")).unwrap())
        .unwrap()
}

/// (source dir under templates/, install target, files) for every manifest category.
fn categories() -> Vec<(String, String, Vec<String>)> {
    manifest()["categories"]
        .as_sequence()
        .unwrap()
        .iter()
        .map(|c| {
            let name = c["name"].as_str().unwrap();
            let src = match name {
                "claude_commands" => "claude/commands",
                "claude_skills" => "claude/skills",
                "github_prompts" => "github/prompts",
                other => other,
            };
            let files = c["files"]
                .as_sequence()
                .unwrap()
                .iter()
                .map(|f| f.as_str().unwrap().to_string())
                .collect();
            (
                src.to_string(),
                c["target"].as_str().unwrap().to_string(),
                files,
            )
        })
        .collect()
}

#[test]
fn manifest_lists_exactly_the_template_files_on_disk() {
    // Catches a template that is never installed (like the old qmetry-publish prompt) and a
    // manifest entry whose file was deleted or renamed.
    let mut listed: Vec<String> = categories()
        .into_iter()
        .flat_map(|(src, _, files)| files.into_iter().map(move |f| format!("{src}/{f}")))
        .collect();
    listed.sort();

    let on_disk: Vec<String> = files_under(&templates_source())
        .into_iter()
        .filter(|f| f != "manifest.yaml")
        .collect();

    assert_eq!(listed, on_disk);
}

#[test]
fn installs_every_manifest_file_and_records_the_version() {
    let env = Env::with_templates();

    let output = run(&mut env.init(&["--yes"]));

    assert!(output.status.success(), "{}", stderr(&output));
    let expected: usize = categories().iter().map(|(_, _, f)| f.len()).sum();
    assert!(stdout(&output).contains(&format!("Installed {expected} files.")));

    for (src, target, files) in categories() {
        for file in files {
            let installed = env.path(&format!("{target}/{file}"));
            let original = templates_source().join(&src).join(&file);
            assert_eq!(
                fs::read(&installed).unwrap_or_else(|_| panic!("{} missing", installed.display())),
                fs::read(original).unwrap(),
                "{target}/{file} differs from its template"
            );
        }
    }

    let version = fs::read_to_string(env.path(".boschifai-version")).unwrap();
    let manifest_version = manifest()["version"].as_str().unwrap().to_string();
    assert!(version.contains(&format!("version: \"{manifest_version}\"")));
    assert!(version.contains(&format!("cli_version: \"{}\"", env!("CARGO_PKG_VERSION"))));
    assert!(version.contains("installed_at: \""));
}

#[test]
fn refuses_to_reinstall_without_force() {
    let env = Env::with_templates();
    assert!(run(&mut env.init(&["--yes"])).status.success());

    let output = run(&mut env.init(&["--yes"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains("already installed"));
}

#[test]
fn force_reinstalls_and_restores_edited_files() {
    let env = Env::with_templates();
    assert!(run(&mut env.init(&["--yes"])).status.success());
    let review = env.path(".claude/commands/boschifai-review.md");
    fs::write(&review, "locally edited").unwrap();

    let output = run(&mut env.init(&["--yes", "--force"]));

    assert!(output.status.success(), "{}", stderr(&output));
    assert_eq!(
        fs::read(&review).unwrap(),
        fs::read(templates_source().join("claude/commands/boschifai-review.md")).unwrap()
    );
}

#[test]
fn force_leaves_files_it_does_not_own_alone() {
    let env = Env::with_templates();
    assert!(run(&mut env.init(&["--yes"])).status.success());
    let own_skill = env.path(".claude/skills/my-team-skill/SKILL.md");
    fs::create_dir_all(own_skill.parent().unwrap()).unwrap();
    fs::write(&own_skill, "ours").unwrap();

    assert!(run(&mut env.init(&["--yes", "--force"])).status.success());

    assert_eq!(fs::read_to_string(&own_skill).unwrap(), "ours");
}

#[test]
fn installs_after_confirming_at_the_prompt() {
    let env = Env::with_templates();

    let output = run_with_stdin(&mut env.init(&[]), "y\n");

    assert!(output.status.success(), "{}", stderr(&output));
    assert!(stdout(&output).contains("Proceed? [Y/n]"));
    assert!(env.path(".boschifai-version").exists());
}

#[test]
fn aborts_without_installing_when_the_prompt_is_declined() {
    let env = Env::with_templates();

    let output = run_with_stdin(&mut env.init(&[]), "n\n");

    assert!(output.status.success());
    assert!(stdout(&output).contains("Aborted."));
    assert!(!env.path(".boschifai-version").exists());
    assert!(!env.path(".claude").exists());
}

#[test]
fn explains_when_templates_are_missing() {
    let env = Env::empty();

    let output = run(&mut env.init(&["--yes"]));

    assert_eq!(output.status.code(), Some(1));
    assert!(stderr(&output).contains("Templates not found"));
    assert!(!Path::new(&env.path(".boschifai-version")).exists());
}

#[test]
fn fails_when_home_is_not_set() {
    let env = Env::with_templates();
    let mut cmd = env.init(&["--yes"]);
    cmd.env_remove("HOME");

    let output = run(&mut cmd);

    assert!(!output.status.success());
    assert!(stderr(&output).contains("Cannot determine home directory"));
}

#[test]
fn reports_a_manifest_entry_whose_template_is_missing() {
    let env = Env::with_templates();
    fs::remove_file(
        env.home
            .path()
            .join(".boschifai/templates/claude/commands/boschifai-review.md"),
    )
    .unwrap();

    let output = run(&mut env.init(&["--yes"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains("Failed to copy"));
    assert!(stderr(&output).contains("boschifai-review.md"));
}
