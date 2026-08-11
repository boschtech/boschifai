use crate::integrations::{jira, qmetry};
use boschifai_core::models::{Requirement, TestCase, TestStep};

pub async fn generate(
    requirement_id: Option<&str>,
    jira_key: Option<&str>,
    qmetry_folder_url: Option<&str>,
    qmetry_api_key: Option<&str>,
    json: bool,
) {
    match (requirement_id, jira_key) {
        (Some(_), Some(_)) => {
            eprintln!("Error: provide either --requirement or --jira-key, not both.");
            std::process::exit(1);
        }
        (None, None) => {
            eprintln!("Error: provide --requirement or --jira-key.");
            std::process::exit(1);
        }
        (Some(req_id), None) => {
            let requirement = Requirement {
                id: req_id.to_string(),
                title: format!("Requirement {}", req_id),
                description: "Auto-generated placeholder requirement".to_string(),
            };
            let test_cases = run_generation(&requirement, json).await;
            output_and_push(test_cases, qmetry_folder_url, qmetry_api_key, json).await;
        }
        (None, Some(key)) => {
            match jira::fetch_issue(key).await {
                Ok(issue) => {
                    let requirement = issue.to_requirement();
                    if !json {
                        println!(
                            "Generating test cases from Jira issue: {} ({})",
                            issue.key, issue.summary
                        );
                    }
                    let test_cases = run_generation(&requirement, json).await;
                    output_and_push(test_cases, qmetry_folder_url, qmetry_api_key, json).await;
                }
                Err(e) => {
                    eprintln!("Error: {e}");
                    std::process::exit(1);
                }
            }
        }
    }
}

async fn run_generation(requirement: &Requirement, json: bool) -> Vec<TestCase> {
    if !json {
        println!("Generating test cases for requirement: {}", requirement.id);
    }
    match boschifai_ai::test_generator::generate_tests_from_requirement(requirement).await {
        Ok(test_cases) => test_cases,
        Err(e) => {
            eprintln!("Error generating test cases: {e}");
            std::process::exit(1);
        }
    }
}

async fn output_and_push(
    test_cases: Vec<TestCase>,
    qmetry_folder_url: Option<&str>,
    qmetry_api_key: Option<&str>,
    json: bool,
) {
    let wants_push = qmetry_folder_url.is_some()
        || std::env::var("BOSCHIFAI_QMETRY_FOLDER_URL").is_ok();

    if wants_push {
        // Resolve folder URL: CLI flag takes precedence over env var
        let resolved_url = qmetry_folder_url
            .map(String::from)
            .or_else(|| std::env::var("BOSCHIFAI_QMETRY_FOLDER_URL").ok());

        let (project_id, folder_id) = match resolved_url.as_deref() {
            Some(url) => match qmetry::parse_folder_url(url) {
                Ok((fid, pid)) => (Some(pid), Some(fid)),
                Err(e) => {
                    if json {
                        println!("{}", serde_json::to_string_pretty(&test_cases).unwrap());
                    }
                    eprintln!("QMetry push skipped: {e}");
                    return;
                }
            },
            None => (None, None),
        };

        if !json {
            let project_label = project_id
                .map(|id| id.to_string())
                .unwrap_or_else(|| "(from env)".to_string());
            let folder_label = folder_id
                .map(|id| format!(" (folder ID: {id})"))
                .unwrap_or_default();
            print_test_cases(&test_cases);
            println!(
                "\nPushing {} test case(s) to QMetry project {project_label}{folder_label}...",
                test_cases.len(),
            );
        }

        match qmetry::load_config(project_id, folder_id, qmetry_api_key) {
            Err(e) => {
                if json {
                    println!("{}", serde_json::to_string_pretty(&test_cases).unwrap());
                }
                eprintln!("QMetry push skipped: {e}");
            }
            Ok(config) => match qmetry::push_test_cases(&config, &test_cases).await {
                Ok(created) => {
                    let keys: Vec<&str> = created.iter().map(|c| c.key.as_str()).collect();
                    if json {
                        let out = serde_json::json!({
                            "test_cases": &test_cases,
                            "qmetry": {
                                "pushed": created.len(),
                                "keys": keys,
                            },
                        });
                        println!("{}", serde_json::to_string_pretty(&out).unwrap());
                    } else {
                        println!("Pushed {} test case(s) to QMetry: {}", created.len(), keys.join(", "));
                    }
                }
                Err(e) => {
                    if json {
                        println!("{}", serde_json::to_string_pretty(&test_cases).unwrap());
                    }
                    eprintln!("QMetry push failed: {e}");
                }
            },
        }
    } else if json {
        println!("{}", serde_json::to_string_pretty(&test_cases).unwrap());
    } else {
        print_test_cases(&test_cases);
    }
}

fn print_test_cases(test_cases: &[TestCase]) {
    println!("Successfully generated {} test case(s):\n", test_cases.len());
    for tc in test_cases {
        println!("  [{}] {}", tc.id, tc.title);
        for (i, step) in tc.steps.iter().enumerate() {
            let text = match step {
                TestStep::Simple(s) => s.as_str(),
                TestStep::Detailed { step_details, .. } => step_details.as_str(),
            };
            println!("    Step {}: {}", i + 1, text);
        }
        println!("    Expected: {}\n", tc.expected_result);
    }
}
