use boschifai_core::models::{TestCase, TestStep};
use serde::Deserialize;
use serde_json::json;
use std::env;

#[derive(Debug)]
pub enum QMetryError {
    MissingBaseUrl,
    MissingApiKey,
    MissingProject,
    InvalidFolderUrl(String),
    AuthError,
    HttpError(String),
    ParseError(String),
}

impl std::fmt::Display for QMetryError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            QMetryError::MissingBaseUrl => write!(
                f,
                "Missing required environment variable: BOSCHIFAI_JIRA_BASE_URL\n\
                 QMetry for Jira uses the same Jira instance — set BOSCHIFAI_JIRA_BASE_URL to your Jira base URL."
            ),
            QMetryError::MissingApiKey => write!(
                f,
                "Missing required environment variable: BOSCHIFAI_QMETRY_TOKEN\n\
                 Set BOSCHIFAI_QMETRY_TOKEN to your QMetry API key.\n\
                 Find it in QMetry → Administration → API Keys, or pass via --api-key."
            ),
            QMetryError::MissingProject => write!(
                f,
                "No QMetry project specified. Use --folder-url with a URL containing projectId, \
                 or set BOSCHIFAI_QMETRY_PROJECT_ID."
            ),
            QMetryError::InvalidFolderUrl(url) => write!(
                f,
                "Could not extract folderId and projectId from URL: {url}\n\
                 Expected format: https://jira.example.com/secure/QTMAction.jspa#/?folderId=<id>&projectId=<id>"
            ),
            QMetryError::AuthError => write!(
                f,
                "QMetry authentication failed. Check that BOSCHIFAI_QMETRY_TOKEN is a valid QMetry API key."
            ),
            QMetryError::HttpError(msg) => write!(f, "QMetry API error: {msg}"),
            QMetryError::ParseError(msg) => write!(f, "Failed to parse QMetry response: {msg}"),
        }
    }
}

pub struct QMetryConfig {
    pub base_url: String,
    pub auth_header: String,
    pub project_id: u64,
    pub folder_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct QMetryCreatedTestCase {
    pub key: String,
}

#[derive(Debug, Deserialize)]
struct NativeCreateResponse {
    key: String,
}

pub fn load_config(
    project_id: Option<u64>,
    folder_id: Option<u64>,
    api_key: Option<&str>,
) -> Result<QMetryConfig, QMetryError> {
    let base_url = env::var("BOSCHIFAI_JIRA_BASE_URL").map_err(|_| QMetryError::MissingBaseUrl)?;
    let resolved_api_key = api_key
        .map(String::from)
        .or_else(|| env::var("BOSCHIFAI_QMETRY_TOKEN").ok())
        .ok_or(QMetryError::MissingApiKey)?;

    let resolved_project_id = project_id
        .or_else(|| {
            env::var("BOSCHIFAI_QMETRY_PROJECT_ID")
                .ok()
                .and_then(|v| v.trim().parse().ok())
        })
        .ok_or(QMetryError::MissingProject)?;

    let resolved_folder_id = folder_id.or_else(|| {
        env::var("BOSCHIFAI_QMETRY_FOLDER_ID").ok().and_then(|v| v.trim().parse().ok())
    });

    Ok(QMetryConfig {
        base_url: base_url.trim_end_matches('/').to_string(),
        auth_header: format!("Bearer {}", resolved_api_key.trim()),
        project_id: resolved_project_id,
        folder_id: resolved_folder_id,
    })
}

/// Parse `folderId` and `projectId` from a QMetry folder browser URL.
/// Returns `(folder_id, project_id)`.
pub fn parse_folder_url(url: &str) -> Result<(u64, u64), QMetryError> {
    let fragment = url.split_once('#').map(|(_, f)| f).unwrap_or("");
    // Handle both `#/?key=val` and `#/Path/To/Page?key=val`
    let query = fragment.split_once('?').map(|(_, q)| q).unwrap_or(
        fragment.trim_start_matches('/').trim_start_matches('?')
    );

    let mut folder_id: Option<u64> = None;
    let mut project_id: Option<u64> = None;

    for pair in query.split('&') {
        if let Some((key, value)) = pair.split_once('=') {
            match key {
                "folderId"  => folder_id  = value.parse().ok(),
                "projectId" => project_id = value.parse().ok(),
                _ => {}
            }
        }
    }

    match (folder_id, project_id) {
        (Some(f), Some(p)) => Ok((f, p)),
        _ => Err(QMetryError::InvalidFolderUrl(url.to_string())),
    }
}

pub async fn push_test_cases(
    config: &QMetryConfig,
    test_cases: &[TestCase],
) -> Result<Vec<QMetryCreatedTestCase>, QMetryError> {
    let client = reqwest::Client::new();
    let create_url = format!("{}/rest/qtm4j/ui/latest/testcases", config.base_url);
    let timeout = std::time::Duration::from_millis(
        env::var("BOSCHIFAI_QMETRY_TIMEOUT_MS")
            .ok()
            .and_then(|v| v.parse().ok())
            .unwrap_or(15_000),
    );
    let debug = env::var("BOSCHIFAI_QMETRY_DEBUG").is_ok();

    let mut created = Vec::with_capacity(test_cases.len());

    for tc in test_cases {
        let body = build_payload(config, tc);

        if debug {
            eprintln!("[QMetry] POST {create_url}");
            eprintln!("[QMetry] Payload: {}", serde_json::to_string_pretty(&body).unwrap_or_default());
        }

        let response = client
            .post(&create_url)
            .header("Authorization", &config.auth_header)
            .header("Content-Type", "application/json")
            .header("Accept", "application/json")
            .header("X-Atlassian-Token", "no-check")
            .timeout(timeout)
            .json(&body)
            .send()
            .await
            .map_err(|e| QMetryError::HttpError(e.to_string()))?;

        let status = response.status();
        if status.as_u16() == 401 || status.as_u16() == 403 {
            return Err(QMetryError::AuthError);
        }
        if !status.is_success() {
            let msg = response.text().await.unwrap_or_default();
            if debug {
                eprintln!("[QMetry] Create response {status}: {msg}");
            }
            return Err(QMetryError::HttpError(format!("HTTP {status}: {msg}")));
        }

        if debug {
            eprintln!("[QMetry] Create response {status}: OK");
        }

        let native: NativeCreateResponse = response
            .json()
            .await
            .map_err(|e| QMetryError::ParseError(e.to_string()))?;

        created.push(QMetryCreatedTestCase { key: native.key });
    }

    Ok(created)
}

fn automation_type_id(tc: &TestCase) -> &'static str {
    match tc.automation_type.as_deref() {
        Some("automation_candidate") => "683",
        Some("automated")            => "682",
        _                            => "681",
    }
}

fn build_payload(config: &QMetryConfig, tc: &TestCase) -> serde_json::Value {
    let last = tc.steps.len().saturating_sub(1);
    let steps: Vec<serde_json::Value> = tc.steps.iter().enumerate().map(|(i, step)| {
        match step {
            TestStep::Simple(text) => json!({
                "stepDetails": text,
                "expectedResult": if i == last { tc.expected_result.as_str() } else { "" },
                "testData": "",
                "isChecked": false,
                "isExpanded": true,
            }),
            TestStep::Detailed { step_details, test_data, expected_result } => json!({
                "stepDetails": step_details,
                "expectedResult": expected_result.as_deref()
                    .unwrap_or(if i == last { tc.expected_result.as_str() } else { "" }),
                "testData": test_data.as_deref().unwrap_or(""),
                "isChecked": false,
                "isExpanded": true,
            }),
        }
    }).collect();

    let mut payload = json!({
        "summary": tc.title,
        "projectId": config.project_id,
        "customFields": [
            { "id": "qcf_1181", "value": automation_type_id(tc) }
        ],
        "steps": steps,
    });

    if let Some(folder_id) = config.folder_id {
        payload["folderId"] = json!(folder_id.to_string());
    }
    if let Some(v) = &tc.description  { payload["description"]  = json!(v); }
    if let Some(v) = &tc.precondition { payload["precondition"]  = json!(v); }
    if let Some(v) = tc.priority      { payload["priority"]      = json!(v); }
    if let Some(v) = tc.status        { payload["status"]        = json!(v); }
    if let Some(v) = &tc.assignee     { payload["assignee"]      = json!(v); }
    if let Some(v) = &tc.reporter     { payload["reporter"]      = json!(v); }
    if let Some(v) = &tc.components   { payload["components"]    = json!(v); }
    if let Some(v) = &tc.labels       { payload["labels"]        = json!(v); }

    payload
}
