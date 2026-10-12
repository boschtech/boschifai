use crate::integrations::jira;
use boschifai_core::models::{Requirement, TestCase, TestStep};

pub async fn generate(requirement_id: Option<&str>, jira_key: Option<&str>, json: bool) {
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
            output(&test_cases, json);
        }
        (None, Some(key)) => match jira::fetch_issue(key).await {
            Ok(issue) => {
                let requirement = issue.to_requirement();
                if !json {
                    println!(
                        "Generating test cases from Jira issue: {} ({})",
                        issue.key, issue.summary
                    );
                }
                let test_cases = run_generation(&requirement, json).await;
                output(&test_cases, json);
            }
            Err(e) => {
                eprintln!("Error: {e}");
                std::process::exit(1);
            }
        },
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

fn output(test_cases: &[TestCase], json: bool) {
    if json {
        println!("{}", serde_json::to_string_pretty(test_cases).unwrap());
    } else {
        print_test_cases(test_cases);
    }
}

fn print_test_cases(test_cases: &[TestCase]) {
    println!(
        "Successfully generated {} test case(s):\n",
        test_cases.len()
    );
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
