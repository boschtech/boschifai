use crate::integrations::jira;
use boschifai_core::models::Requirement;

pub async fn review(file_path: Option<&str>, jira_key: Option<&str>, json: bool) {
    match (file_path, jira_key) {
        (Some(_), Some(_)) => {
            eprintln!("Error: provide either a file path or --jira-key, not both.");
            std::process::exit(1);
        }
        (None, None) => {
            eprintln!("Error: provide a file path or --jira-key.");
            std::process::exit(1);
        }
        (Some(path), None) => {
            // Existing file-based review
            let requirements = vec![Requirement {
                id: "REQ-001".to_string(),
                title: format!("Requirement from {}", path),
                description: "Parsed from the provided requirements document".to_string(),
            }];
            if json {
                println!("{}", serde_json::to_string_pretty(&requirements).unwrap());
            } else {
                println!("Reviewing requirements from: {}", path);
                for req in &requirements {
                    println!("  [{}] {} - {}", req.id, req.title, req.description);
                }
            }
        }
        (None, Some(key)) => {
            // Jira-based review
            match jira::fetch_issue(key).await {
                Ok(issue) => {
                    if json {
                        println!("{}", serde_json::to_string_pretty(&issue).unwrap());
                    } else {
                        println!("Reviewing requirements from Jira: {}", issue.key);
                        println!("  Type:        {}", issue.issue_type);
                        println!("  Summary:     {}", issue.summary);
                        println!("  Status:      {}", issue.status);
                        if let Some(ref assignee) = issue.assignee {
                            println!("  Assignee:    {}", assignee);
                        }
                        if let Some(ref epic) = issue.epic_link {
                            println!("  Epic:        {}", epic);
                        }
                        if let Some(sp) = issue.story_points {
                            println!("  Story Pts:   {}", sp);
                        }
                        if !issue.labels.is_empty() {
                            println!("  Labels:      {}", issue.labels.join(", "));
                        }
                        println!("\n  Description:\n  {}", issue.description.replace('\n', "\n  "));
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
