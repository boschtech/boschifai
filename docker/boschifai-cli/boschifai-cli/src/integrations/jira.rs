use boschifai_core::models::JiraIssueSource;
use serde_json::Value;
use std::env;

const ALLOWED_TYPES: &[&str] = &["Epic", "Story", "Feature"];

#[derive(Debug)]
pub enum JiraError {
    MissingEnvVar(String),
    HttpError(String),
    NotFound(String),
    UnsupportedIssueType { key: String, issue_type: String },
    ParseError(String),
}

impl std::fmt::Display for JiraError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            JiraError::MissingEnvVar(var) => write!(
                f,
                "Missing required environment variable: {var}\n\
                 Required Jira env vars:\n  \
                 BOSCHIFAI_JIRA_BASE_URL  — Jira Server/DC base URL (e.g. https://jira.example.com)\n  \
                 BOSCHIFAI_JIRA_TOKEN     — Pre-encoded Base64 token (base64 of username:password)"
            ),
            JiraError::HttpError(msg) => write!(f, "Jira API error: {msg}"),
            JiraError::NotFound(key) => write!(f, "Jira issue not found: {key}"),
            JiraError::UnsupportedIssueType { key, issue_type } => write!(
                f,
                "Issue {key} has type \"{issue_type}\" which is not supported.\n\
                 Allowed types: Epic, Story, Feature.\n\
                 Set BOSCHIFAI_JIRA_ALLOWED_TYPES to override (comma-separated)."
            ),
            JiraError::ParseError(msg) => write!(f, "Failed to parse Jira response: {msg}"),
        }
    }
}

struct JiraConfig {
    base_url: String,
    auth_header: String,
    story_points_field: String,
    epic_link_field: String,
    allowed_types: Vec<String>,
}

fn load_config() -> Result<JiraConfig, JiraError> {
    let base_url = env::var("BOSCHIFAI_JIRA_BASE_URL")
        .map_err(|_| JiraError::MissingEnvVar("BOSCHIFAI_JIRA_BASE_URL".into()))?;
    let token = env::var("BOSCHIFAI_JIRA_TOKEN")
        .map_err(|_| JiraError::MissingEnvVar("BOSCHIFAI_JIRA_TOKEN".into()))?;

    let auth_header = format!("Basic {}", token.trim());

    let story_points_field = env::var("BOSCHIFAI_JIRA_STORY_POINTS_FIELD")
        .unwrap_or_else(|_| "customfield_10028".into());
    let epic_link_field = env::var("BOSCHIFAI_JIRA_EPIC_LINK_FIELD")
        .unwrap_or_else(|_| "customfield_10014".into());

    let allowed_types = env::var("BOSCHIFAI_JIRA_ALLOWED_TYPES")
        .map(|v| v.split(',').map(|s| s.trim().to_string()).collect())
        .unwrap_or_else(|_| ALLOWED_TYPES.iter().map(|s| s.to_string()).collect());

    let base_url = base_url.trim_end_matches('/').to_string();

    Ok(JiraConfig {
        base_url,
        auth_header,
        story_points_field,
        epic_link_field,
        allowed_types,
    })
}

/// Fetch a Jira issue by key and return a normalized JiraIssueSource.
/// Only Epic/Story/Feature issue types are accepted (configurable via BOSCHIFAI_JIRA_ALLOWED_TYPES).
pub async fn fetch_issue(key: &str) -> Result<JiraIssueSource, JiraError> {
    let config = load_config()?;

    let url = format!(
        "{}/rest/api/2/issue/{}",
        config.base_url, key
    );

    let client = reqwest::Client::new();
    let response = client
        .get(&url)
        .header("Authorization", &config.auth_header)
        .header("Accept", "application/json")
        .timeout(std::time::Duration::from_millis(
            env::var("BOSCHIFAI_JIRA_TIMEOUT_MS")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(10_000),
        ))
        .send()
        .await
        .map_err(|e| JiraError::HttpError(e.to_string()))?;

    let status = response.status();
    if status.as_u16() == 404 {
        return Err(JiraError::NotFound(key.to_string()));
    }
    if status.as_u16() == 401 || status.as_u16() == 403 {
        return Err(JiraError::HttpError(format!(
            "Authentication failed (HTTP {status}). Check BOSCHIFAI_JIRA_TOKEN (base64 of username:password)."
        )));
    }
    if !status.is_success() {
        return Err(JiraError::HttpError(format!("HTTP {status}")));
    }

    let body: Value = response
        .json()
        .await
        .map_err(|e| JiraError::ParseError(e.to_string()))?;

    let fields = body.get("fields")
        .ok_or_else(|| JiraError::ParseError("missing 'fields' in response".into()))?;

    let issue_type = fields
        .get("issuetype")
        .and_then(|v| v.get("name"))
        .and_then(|v| v.as_str())
        .unwrap_or("Unknown")
        .to_string();

    // Validate issue type
    let type_allowed = config
        .allowed_types
        .iter()
        .any(|t| t.eq_ignore_ascii_case(&issue_type));
    if !type_allowed {
        return Err(JiraError::UnsupportedIssueType {
            key: key.to_string(),
            issue_type,
        });
    }

    let summary = fields
        .get("summary")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();

    let description = fields
        .get("description")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();

    let labels: Vec<String> = fields
        .get("labels")
        .and_then(|v| v.as_array())
        .map(|arr| {
            arr.iter()
                .filter_map(|v| v.as_str().map(String::from))
                .collect()
        })
        .unwrap_or_default();

    let status_name = fields
        .get("status")
        .and_then(|v| v.get("name"))
        .and_then(|v| v.as_str())
        .unwrap_or("Unknown")
        .to_string();

    let assignee = fields
        .get("assignee")
        .and_then(|v| v.get("displayName"))
        .and_then(|v| v.as_str())
        .map(String::from);

    let story_points = fields
        .get(&config.story_points_field)
        .and_then(|v| v.as_f64());

    let epic_link = fields
        .get(&config.epic_link_field)
        .and_then(|v| v.as_str())
        .map(String::from);

    Ok(JiraIssueSource {
        key: body
            .get("key")
            .and_then(|v| v.as_str())
            .unwrap_or(key)
            .to_string(),
        issue_type,
        summary,
        description,
        labels,
        status: status_name,
        assignee,
        story_points,
        epic_link,
    })
}
