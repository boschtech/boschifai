use crate::integrations::jira;
use boschifai_core::models::TestStrategy;

pub async fn create(requirement_id: Option<&str>, jira_key: Option<&str>, json: bool) {
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
            // Existing requirement-id-based strategy
            let strategy = TestStrategy {
                id: format!("TS-{}", req_id),
                requirement_id: req_id.to_string(),
                approach: "Boundary value analysis combined with equivalence partitioning"
                    .to_string(),
            };
            if json {
                println!("{}", serde_json::to_string_pretty(&strategy).unwrap());
            } else {
                println!("Creating test strategy for requirement: {}", req_id);
                println!("  Strategy: {} - {}", strategy.id, strategy.approach);
            }
        }
        (None, Some(key)) => {
            // Jira-based strategy
            match jira::fetch_issue(key).await {
                Ok(issue) => {
                    let req = issue.to_requirement();
                    let strategy = TestStrategy {
                        id: format!("TS-{}", req.id),
                        requirement_id: req.id.clone(),
                        approach: "Boundary value analysis combined with equivalence partitioning"
                            .to_string(),
                    };
                    if json {
                        println!("{}", serde_json::to_string_pretty(&strategy).unwrap());
                    } else {
                        println!(
                            "Creating test strategy for Jira issue: {} ({})",
                            issue.key, issue.summary
                        );
                        println!("  Strategy: {} - {}", strategy.id, strategy.approach);
                    }
                }
                Err(e) => {
                    eprintln!("Error: {}", e);
                    std::process::exit(1);
                }
            }
        }
    }
}
