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

// ── --profile / --stack ─────────────────────────────────────────────────────────────────

/// Every file under `.claude/` and `.github/` in the project, relative to the project root.
fn installed(env: &Env) -> Vec<String> {
    let mut all = Vec::new();
    for dir in [".claude", ".github"] {
        let root = env.path(dir);
        if root.exists() {
            all.extend(files_under(&root).into_iter().map(|f| format!("{dir}/{f}")));
        }
    }
    all.sort();
    all
}

/// Installed skill names, sorted by name (not by file path, where `-` sorts before `/`).
fn installed_skills(env: &Env) -> Vec<String> {
    let mut skills: Vec<String> = installed(env)
        .into_iter()
        .filter_map(|f| {
            f.strip_prefix(".claude/skills/")
                .map(|s| s.trim_end_matches("/SKILL.md").to_string())
        })
        .collect();
    skills.sort();
    skills
}

const PIPELINE_COMMANDS: [&str; 4] = [
    ".claude/commands/boschifai-gen-component.md",
    ".claude/commands/boschifai-review.md",
    ".claude/commands/boschifai-test-cases.md",
    ".claude/commands/boschifai-test-plan.md",
];

const PIPELINE_SKILLS: [&str; 7] = [
    "boschifai-gen-component",
    "boschifai-github-conventions",
    "boschifai-project-template",
    "boschifai-review-standards",
    "boschifai-reviewer",
    "boschifai-test-levels",
    "boschifai-test-patterns",
];

fn with_stack_skills(extra: &[&str]) -> Vec<String> {
    let mut all: Vec<String> = PIPELINE_SKILLS
        .iter()
        .chain(extra)
        .map(|s| s.to_string())
        .collect();
    all.sort();
    all.dedup();
    all
}

#[test]
fn every_profile_and_stack_file_is_also_in_the_full_install() {
    let manifest = manifest();
    let listed: Vec<String> = categories()
        .into_iter()
        .flat_map(|(src, _, files)| files.into_iter().map(move |f| format!("{src}/{f}")))
        .collect();
    let src_of = |category: &str| match category {
        "claude_commands" => "claude/commands",
        "claude_skills" => "claude/skills",
        "github_prompts" => "github/prompts",
        other => panic!("unknown category {other}"),
    };

    let groups = manifest["profiles"]
        .as_mapping()
        .unwrap()
        .values()
        .chain(manifest["stacks"].as_mapping().unwrap().values());
    for group in groups {
        for (category, files) in group["include"].as_mapping().unwrap() {
            for file in files.as_sequence().unwrap() {
                let path = format!(
                    "{}/{}",
                    src_of(category.as_str().unwrap()),
                    file.as_str().unwrap()
                );
                assert!(listed.contains(&path), "{path} is not in categories");
            }
        }
    }
}

#[test]
fn pipeline_profile_installs_only_what_the_pipeline_uses() {
    let env = Env::with_templates();

    let output = run(&mut env.init(&["--yes", "--profile", "pipeline"]));

    assert!(output.status.success(), "{}", stderr(&output));
    let commands: Vec<String> = installed(&env)
        .into_iter()
        .filter(|f| f.starts_with(".claude/commands/"))
        .collect();
    assert_eq!(commands, PIPELINE_COMMANDS);
    assert_eq!(installed_skills(&env), with_stack_skills(&[]));
    assert!(
        !env.path(".github").exists(),
        "Copilot prompts are not part of the pipeline"
    );

    let out = stdout(&output);
    assert!(out.contains("Profile: pipeline"));
    assert!(out.contains("Stacks (detected): none — installing base skills only"));
    assert!(out.contains("Installed 11 files."));

    let version = fs::read_to_string(env.path(".boschifai-version")).unwrap();
    assert!(version.contains("profile: \"pipeline\""));
    assert!(version.contains("stacks: []"));
}

#[test]
fn installed_profile_files_are_exact_copies_of_the_templates() {
    let env = Env::with_templates();
    fs::write(
        env.path("composer.json"),
        r#"{"require": {"laravel/framework": "^11.0"}}"#,
    )
    .unwrap();

    assert!(run(&mut env.init(&["--yes", "--profile", "pipeline"]))
        .status
        .success());

    for file in installed(&env) {
        let template = file
            .replacen(".claude/commands/", "claude/commands/", 1)
            .replacen(".claude/skills/", "claude/skills/", 1);
        assert_eq!(
            fs::read(env.path(&file)).unwrap(),
            fs::read(templates_source().join(&template)).unwrap(),
            "{file}"
        );
    }
}

fn detected_skills(files: &[(&str, &str)]) -> (Vec<String>, String) {
    let env = Env::with_templates();
    for (name, content) in files {
        fs::write(env.path(name), content).unwrap();
    }
    let output = run(&mut env.init(&["--yes", "--profile", "pipeline"]));
    assert!(output.status.success(), "{}", stderr(&output));

    (installed_skills(&env), stdout(&output))
}

#[test]
fn detects_a_laravel_project() {
    let (skills, out) = detected_skills(&[(
        "composer.json",
        r#"{"require": {"php": "^8.2", "laravel/framework": "^11.31"}}"#,
    )]);

    assert!(out.contains("Stacks (detected): laravel"));
    assert_eq!(
        skills,
        with_stack_skills(&[
            "boschifai-gen-feature-php-laravel",
            "boschifai-gen-unit-php-laravel",
            "boschifai-gen-unit"
        ])
    );
}

#[test]
fn a_plain_php_project_is_not_laravel() {
    let (skills, _) =
        detected_skills(&[("composer.json", r#"{"require": {"symfony/console": "^7"}}"#)]);

    assert_eq!(skills, with_stack_skills(&[]));
}

#[test]
fn detects_a_flutter_app() {
    let (skills, out) = detected_skills(&[(
        "pubspec.yaml",
        "name: redrabbit\ndependencies:\n  flutter:\n    sdk: flutter\n",
    )]);

    assert!(out.contains("Stacks (detected): flutter"));
    assert_eq!(
        skills,
        with_stack_skills(&["boschifai-gen-unit-flutter", "boschifai-gen-unit"])
    );
}

#[test]
fn detects_java_rest_and_adds_event_skills_only_for_messaging_services() {
    let (rest, out) = detected_skills(&[(
        "pom.xml",
        "<artifactId>spring-boot-starter-web</artifactId>",
    )]);
    assert!(out.contains("Stacks (detected): java-rest"));
    assert_eq!(
        rest,
        with_stack_skills(&["boschifai-gen-component-java-rest"])
    );

    let (event, out) = detected_skills(&[(
        "build.gradle.kts",
        "implementation(\"com.solace.spring.boot:solace-spring-boot-starter\")",
    )]);
    assert!(out.contains("Stacks (detected): java-event, java-rest"));
    assert_eq!(
        event,
        with_stack_skills(&[
            "boschifai-gen-component-java-event",
            "boschifai-gen-component-java-rest"
        ])
    );
}

#[test]
fn detects_a_node_bff_only_from_real_dependencies() {
    let (bff, out) =
        detected_skills(&[("package.json", r#"{"dependencies": {"koa": "^2.15.0"}}"#)]);
    assert!(out.contains("Stacks (detected): node-bff"));
    assert_eq!(
        bff,
        with_stack_skills(&["boschifai-gen-component-nextjs-bff"])
    );

    // A frontend-only package.json (like this app's own Vue/Vite one) is not a BFF.
    let (frontend, _) = detected_skills(&[(
        "package.json",
        r#"{"devDependencies": {"vite": "^6", "vitest": "^3"}, "dependencies": {"vue": "2.7"}}"#,
    )]);
    assert_eq!(frontend, with_stack_skills(&[]));
}

#[test]
fn detects_several_stacks_in_one_repository() {
    let (skills, out) = detected_skills(&[
        (
            "composer.json",
            r#"{"require": {"laravel/framework": "^11"}}"#,
        ),
        ("package.json", r#"{"dependencies": {"express": "^4"}}"#),
    ]);

    assert!(out.contains("Stacks (detected): laravel, node-bff"));
    assert!(skills.contains(&"boschifai-gen-feature-php-laravel".to_string()));
    assert!(skills.contains(&"boschifai-gen-component-nextjs-bff".to_string()));
}

#[test]
fn stack_flag_overrides_detection() {
    let env = Env::with_templates();
    fs::write(
        env.path("composer.json"),
        r#"{"require": {"laravel/framework": "^11"}}"#,
    )
    .unwrap();

    let output = run(&mut env.init(&[
        "--yes",
        "--profile",
        "pipeline",
        "--stack",
        "flutter,java-rest",
        "--stack",
        "flutter",
    ]));

    assert!(output.status.success(), "{}", stderr(&output));
    assert!(stdout(&output).contains("Stacks (from --stack): flutter, java-rest"));
    let skills = installed_skills(&env);
    assert!(skills.contains(&"boschifai-gen-unit-flutter".to_string()));
    assert!(skills.contains(&"boschifai-gen-component-java-rest".to_string()));
    assert!(!skills.contains(&"boschifai-gen-feature-php-laravel".to_string()));
    let version = fs::read_to_string(env.path(".boschifai-version")).unwrap();
    assert!(version.contains("stacks: [\"flutter\", \"java-rest\"]"));
}

#[test]
fn rejects_an_unknown_profile_or_stack() {
    let env = Env::with_templates();

    let profile = run(&mut env.init(&["--yes", "--profile", "everything"]));
    assert!(!profile.status.success());
    assert!(
        stderr(&profile).contains("Unknown profile \"everything\". Available profiles: pipeline")
    );

    let stack = run(&mut env.init(&["--yes", "--profile", "pipeline", "--stack", "cobol"]));
    assert!(!stack.status.success());
    assert!(stderr(&stack).contains(
        "Unknown stack \"cobol\". Available stacks: flutter, java-event, java-rest, laravel, node-bff"
    ));

    assert!(!env.path(".boschifai-version").exists());
}

#[test]
fn stack_requires_a_profile() {
    let env = Env::with_templates();

    let output = run(&mut env.init(&["--yes", "--stack", "laravel"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains("--profile"));
}

#[test]
fn reinstalling_with_a_profile_removes_templates_it_does_not_include() {
    let env = Env::with_templates();
    assert!(run(&mut env.init(&["--yes"])).status.success());
    assert!(env
        .path(".claude/skills/boschifai-gen-storybook/SKILL.md")
        .exists());

    let output = run(&mut env.init(&["--yes", "--force", "--profile", "pipeline"]));

    assert!(output.status.success(), "{}", stderr(&output));
    assert_eq!(installed_skills(&env), with_stack_skills(&[]));
    assert!(
        !env.path(".github/prompts").exists()
            || installed(&env).iter().all(|f| !f.starts_with(".github/"))
    );
}

#[test]
fn reports_a_profile_that_lists_a_file_missing_from_its_category() {
    let env = Env::with_templates();
    let manifest_path = env.home.path().join(".boschifai/templates/manifest.yaml");
    let manifest = fs::read_to_string(&manifest_path)
        .unwrap()
        .replace(
            "        - boschifai-review.md\n        - boschifai-test-plan.md\n        - boschifai-test-cases.md\n        - boschifai-gen-component.md",
            "        - boschifai-review.md\n        - boschifai-not-real.md",
        );
    fs::write(&manifest_path, manifest).unwrap();

    let output = run(&mut env.init(&["--yes", "--profile", "pipeline"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains(
        "Manifest error: \"boschifai-not-real.md\" is not listed under the claude_commands category"
    ));
}

#[test]
fn reports_a_profile_with_an_unknown_category() {
    let env = Env::with_templates();
    let manifest_path = env.home.path().join(".boschifai/templates/manifest.yaml");
    let manifest = fs::read_to_string(&manifest_path).unwrap().replacen(
        "      claude_commands:\n        - boschifai-review.md",
        "      cursor_rules:\n        - boschifai-review.md",
        1,
    );
    fs::write(&manifest_path, manifest).unwrap();

    let output = run(&mut env.init(&["--yes", "--profile", "pipeline"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains("Manifest error: unknown category \"cursor_rules\""));
}
