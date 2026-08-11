use crate::integrations::jira;

pub async fn generate(file_path: Option<&str>, jira_key: Option<&str>, json: bool) {
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
                        "command": "data generate",
                        "source": path,
                        "output": format!("test_data_{}.md", sanitize(path))
                    })
                );
            } else {
                println!("Generating test data specification from: {}", path);
                println!("  Output: test_data_{}.md", sanitize(path));
            }
        }
        (None, Some(key)) => match jira::fetch_issue(key).await {
            Ok(issue) => {
                if json {
                    println!(
                        "{}",
                        serde_json::json!({
                            "command": "data generate",
                            "source": issue.key,
                            "summary": issue.summary,
                            "output": format!("test_data_{}.md", issue.key.to_lowercase())
                        })
                    );
                } else {
                    println!(
                        "Generating test data specification from Jira issue: {} ({})",
                        issue.key, issue.summary
                    );
                    println!(
                        "  Output: test_data_{}.md",
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
