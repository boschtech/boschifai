use crate::integrations::jira;

pub async fn review(file_path: Option<&str>, jira_key: Option<&str>, json: bool) {
    match (file_path, jira_key) {
        (Some(_), Some(_)) => {
            eprintln!("Error: provide either --file or --jira-key, not both.");
            std::process::exit(1);
        }
        (None, None) => {
            eprintln!("Error: provide --file or --jira-key.");
            std::process::exit(1);
        }
        (Some(path), None) => {
            if json {
                println!(
                    "{}",
                    serde_json::json!({
                        "command": "testability review",
                        "source": path,
                        "output": format!("testability_review_{}.md", sanitize(path))
                    })
                );
            } else {
                println!("Reviewing testability of: {}", path);
                println!(
                    "  Output: testability_review_{}.md",
                    sanitize(path)
                );
            }
        }
        (None, Some(key)) => match jira::fetch_issue(key).await {
            Ok(issue) => {
                if json {
                    println!(
                        "{}",
                        serde_json::json!({
                            "command": "testability review",
                            "source": issue.key,
                            "summary": issue.summary,
                            "output": format!("testability_review_{}.md", issue.key.to_lowercase())
                        })
                    );
                } else {
                    println!(
                        "Reviewing testability of Jira issue: {} ({})",
                        issue.key, issue.summary
                    );
                    println!(
                        "  Output: testability_review_{}.md",
                        issue.key.to_lowercase()
                    );
                }
            }
            Err(e) => {
                eprintln!("Error: {}", e);
                std::process::exit(1);
            }
        },
    }
}

fn sanitize(path: &str) -> String {
    std::path::Path::new(path)
        .file_stem()
        .and_then(|s| s.to_str())
        .unwrap_or("output")
        .to_string()
}
