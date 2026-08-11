use crate::core::compress::{apply_compression, compress_file_tree, truncate_to_lines, FilterLevel};
use boschifai_core::models::{GitLabFile, GitLabRepoContext};
use serde_json::Value;
use std::env;

const MAX_STACK_FILE_LINES: usize = 100;
const MAX_SOURCE_FILES: usize = 20;
const MAX_TEST_FILES: usize = 10;
const MAX_FILE_TREE_ENTRIES: usize = 300;

#[derive(Debug)]
pub enum GitLabError {
    MissingToken,
    InvalidUrl(String),
    HttpError(String),
    NotFound(String),
    ParseError(String),
}

impl std::fmt::Display for GitLabError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            GitLabError::MissingToken => write!(
                f,
                "Missing BOSCHIFAI_GITLAB_TOKEN.\n\
                 Set BOSCHIFAI_GITLAB_TOKEN to a GitLab personal access token with read_repository scope.\n\
                 For public repositories, set BOSCHIFAI_GITLAB_TOKEN to an empty string to skip auth."
            ),
            GitLabError::InvalidUrl(url) => write!(
                f,
                "Cannot parse GitLab project from URL: {url}\n\
                 Expected format: https://gitlab.com/namespace/project  or  namespace/project"
            ),
            GitLabError::HttpError(msg) => write!(f, "GitLab API error: {msg}"),
            GitLabError::NotFound(path) => write!(f, "GitLab resource not found: {path}"),
            GitLabError::ParseError(msg) => write!(f, "Failed to parse GitLab response: {msg}"),
        }
    }
}

fn load_token() -> Result<Option<String>, GitLabError> {
    match env::var("BOSCHIFAI_GITLAB_TOKEN") {
        Ok(t) if t.trim().is_empty() => Ok(None),
        Ok(t) => Ok(Some(t.trim().to_string())),
        Err(_) => Err(GitLabError::MissingToken),
    }
}

/// Parse a GitLab API base URL and URL-encoded project id from a full repo URL.
/// The host is extracted directly from the URL, so self-hosted instances work
/// without any extra configuration.
///
/// Accepts URLs with or without a `.git` suffix:
///   https://gitlab.com/group/project
///   https://gitlab.com/group/project.git
fn parse_repo(repo_url: &str) -> Result<(String, String), GitLabError> {
    let url = repo_url.trim().trim_end_matches('/').trim_end_matches(".git");

    if !url.starts_with("http://") && !url.starts_with("https://") {
        return Err(GitLabError::InvalidUrl(format!(
            "{repo_url}\nProvide the full URL including the scheme, e.g. https://gitlab.com/group/project"
        )));
    }

    // Split into ["https:", "", "gitlab.example.com", "group/sub/project"]
    let parts: Vec<&str> = url.splitn(4, '/').collect();
    if parts.len() < 4 || parts[3].is_empty() || !parts[3].contains('/') {
        return Err(GitLabError::InvalidUrl(format!(
            "{repo_url}\nExpected format: https://gitlab.com/namespace/project"
        )));
    }

    let base_url = format!("{}//{}", parts[0], parts[2]);
    let project_id = parts[3].replace('/', "%2F");
    Ok((base_url, project_id))
}

async fn get_json(
    client: &reqwest::Client,
    url: &str,
    token: &Option<String>,
) -> Result<Value, GitLabError> {
    let mut req = client.get(url).header("Accept", "application/json");
    if let Some(t) = token {
        req = req.header("PRIVATE-TOKEN", t);
    }
    let response = req
        .timeout(std::time::Duration::from_millis(
            env::var("BOSCHIFAI_GITLAB_TIMEOUT_MS")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(15_000),
        ))
        .send()
        .await
        .map_err(|e| GitLabError::HttpError(e.to_string()))?;

    let status = response.status();
    if status.as_u16() == 404 {
        return Err(GitLabError::NotFound(url.to_string()));
    }
    if status.as_u16() == 401 || status.as_u16() == 403 {
        return Err(GitLabError::HttpError(format!(
            "Authentication failed (HTTP {status}). Check BOSCHIFAI_GITLAB_TOKEN."
        )));
    }
    if !status.is_success() {
        return Err(GitLabError::HttpError(format!("HTTP {status} for {url}")));
    }

    response
        .json()
        .await
        .map_err(|e| GitLabError::ParseError(e.to_string()))
}

async fn get_text(
    client: &reqwest::Client,
    url: &str,
    token: &Option<String>,
) -> Result<String, GitLabError> {
    let mut req = client.get(url);
    if let Some(t) = token {
        req = req.header("PRIVATE-TOKEN", t);
    }
    let response = req
        .timeout(std::time::Duration::from_millis(
            env::var("BOSCHIFAI_GITLAB_TIMEOUT_MS")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(15_000),
        ))
        .send()
        .await
        .map_err(|e| GitLabError::HttpError(e.to_string()))?;

    let status = response.status();
    if status.as_u16() == 404 {
        return Ok(String::new()); // missing file is non-fatal
    }
    if !status.is_success() {
        return Err(GitLabError::HttpError(format!("HTTP {status} for {url}")));
    }

    response
        .text()
        .await
        .map_err(|e| GitLabError::ParseError(e.to_string()))
}

/// Detect tech stack from file paths and stack-file contents.
fn detect_stack(tree_paths: &[String], stack_files: &[GitLabFile]) -> Vec<String> {
    let mut stack = Vec::new();

    let path_hints: &[(&str, &str)] = &[
        ("package.json", "nodejs"),
        ("Cargo.toml", "rust"),
        ("pom.xml", "java-maven"),
        ("build.gradle", "java-gradle"),
        ("pyproject.toml", "python"),
        ("setup.py", "python"),
        ("requirements.txt", "python"),
        ("go.mod", "go"),
        ("*.csproj", "dotnet"),
        ("Gemfile", "ruby"),
    ];

    for (marker, label) in path_hints {
        let marker = *marker;
        let matches = if marker.starts_with('*') {
            let ext = &marker[1..];
            tree_paths.iter().any(|p| p.ends_with(ext))
        } else {
            tree_paths.iter().any(|p| p == marker || p.ends_with(&format!("/{marker}")))
        };
        if matches {
            stack.push(label.to_string());
        }
    }

    // Refine nodejs: check if TypeScript is present
    if stack.contains(&"nodejs".to_string()) {
        let has_ts = tree_paths.iter().any(|p| p.ends_with(".ts") || p.ends_with(".tsx"));
        let has_tsconfig = tree_paths.iter().any(|p| p.ends_with("tsconfig.json"));
        if has_ts || has_tsconfig {
            stack.push("typescript".to_string());
        }

        // Check for frameworks in package.json
        for f in stack_files {
            if f.path.ends_with("package.json") {
                if f.content.contains("\"next\"") || f.content.contains("\"next\":") {
                    stack.push("nextjs".to_string());
                }
                if f.content.contains("\"react\"") || f.content.contains("\"react\":") {
                    stack.push("react".to_string());
                }
                if f.content.contains("\"@nestjs/core\"") {
                    stack.push("nestjs".to_string());
                }
                if f.content.contains("\"jest\"") || f.content.contains("\"vitest\"") {
                    stack.push("jest-or-vitest".to_string());
                }
                if f.content.contains("\"playwright\"") || f.content.contains("\"cypress\"") {
                    stack.push("e2e-browser".to_string());
                }
            }
        }
    }

    if stack.contains(&"java-maven".to_string()) || stack.contains(&"java-gradle".to_string()) {
        for f in stack_files {
            if f.path.ends_with("pom.xml") || f.path.ends_with("build.gradle") {
                if f.content.contains("spring-boot") || f.content.contains("spring-web") {
                    stack.push("spring-boot".to_string());
                }
                if f.content.contains("junit") {
                    stack.push("junit".to_string());
                }
            }
        }
    }

    stack.dedup();
    stack
}

/// Walk the repository tree up to a reasonable depth and return all file paths.
async fn fetch_tree(
    client: &reqwest::Client,
    api_base: &str,
    project_id: &str,
    token: &Option<String>,
    branch: &str,
) -> Result<Vec<String>, GitLabError> {
    let url = format!(
        "{api_base}/api/v4/projects/{project_id}/repository/tree\
         ?recursive=true&per_page=100&ref={branch}"
    );
    let body = get_json(client, &url, token).await?;

    let paths: Vec<String> = body
        .as_array()
        .unwrap_or(&vec![])
        .iter()
        .filter_map(|entry| {
            if entry.get("type").and_then(|v| v.as_str()) == Some("blob") {
                entry.get("path").and_then(|v| v.as_str()).map(String::from)
            } else {
                None
            }
        })
        .collect();

    Ok(paths)
}

/// Fetch the raw content of a single file, returning an empty string on 404.
async fn fetch_file_content(
    client: &reqwest::Client,
    api_base: &str,
    project_id: &str,
    token: &Option<String>,
    branch: &str,
    file_path: &str,
) -> Result<GitLabFile, GitLabError> {
    let encoded_path = file_path.replace('/', "%2F");
    let url = format!(
        "{api_base}/api/v4/projects/{project_id}/repository/files/{encoded_path}/raw?ref={branch}"
    );
    let content = get_text(client, &url, token).await?;
    Ok(GitLabFile {
        path: file_path.to_string(),
        content,
    })
}

const STACK_FILE_NAMES: &[&str] = &[
    "package.json",
    "Cargo.toml",
    "pom.xml",
    "build.gradle",
    "pyproject.toml",
    "go.mod",
    "tsconfig.json",
];

const TEST_PATH_PATTERNS: &[&str] = &[
    "/test/", "/tests/", "/__tests__/", "/spec/", "/specs/",
    ".test.", ".spec.", "_test.", "_spec.",
];

const SOURCE_PATH_PREFIXES: &[&str] = &["src/", "lib/", "app/", "pkg/", "internal/", "tests/", "test/", "e2e/", "pages/", "features/", "support/"];

const SOURCE_EXTENSIONS: &[&str] = &[".ts", ".tsx", ".js", ".jsx", ".mjs", ".java", ".kt", ".go", ".rs", ".py", ".rb", ".cs"];

/// True if the file has a code extension (used for fallback when no standard src/ dir exists).
fn is_code_file(path: &str) -> bool {
    SOURCE_EXTENSIONS.iter().any(|ext| path.ends_with(ext))
        && !is_stack_file(path)
}

fn is_stack_file(path: &str) -> bool {
    let name = path.rsplit('/').next().unwrap_or(path);
    STACK_FILE_NAMES.contains(&name)
}

fn is_test_file(path: &str) -> bool {
    TEST_PATH_PATTERNS.iter().any(|pat| path.contains(pat))
}

fn is_source_file(path: &str) -> bool {
    SOURCE_PATH_PREFIXES.iter().any(|pfx| path.starts_with(pfx))
        && !is_test_file(path)
        && !path.ends_with(".md")
        && !path.ends_with(".json")
        && !path.ends_with(".yaml")
        && !path.ends_with(".yml")
}

/// Fetch and analyse a GitLab repository, returning a structured context for AI test generation.
///
/// `branch` overrides the project's default branch for all tree and file fetches.
/// `filter_level` controls comment/body stripping on source files (default: Minimal).
/// `max_lines_per_file` hard-caps each source file's line count (default: 300).
pub async fn fetch_repo_context(
    repo_url: &str,
    branch: Option<&str>,
    filter_level: FilterLevel,
    max_lines_per_file: usize,
) -> Result<GitLabRepoContext, GitLabError> {
    let token = load_token()?;
    let (api_base, project_id) = parse_repo(repo_url)?;
    let client = reqwest::Client::new();
    let api_base = &api_base;
    let token = &token;

    // Fetch project metadata
    let project_url = format!("{api_base}/api/v4/projects/{project_id}");
    let project_meta = get_json(&client, &project_url, token).await?;

    let default_branch = branch
        .map(String::from)
        .unwrap_or_else(|| {
            project_meta
                .get("default_branch")
                .and_then(|v| v.as_str())
                .unwrap_or("master")
                .to_string()
        });

    let description = project_meta
        .get("description")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .map(String::from);

    let project_path = project_meta
        .get("path_with_namespace")
        .and_then(|v| v.as_str())
        .unwrap_or(repo_url)
        .to_string();

    // Fetch full file tree
    let tree = fetch_tree(&client, api_base, &project_id, token, &default_branch).await?;

    // Identify which files to fetch
    let stack_file_paths: Vec<&str> = tree.iter()
        .filter(|p| is_stack_file(p))
        .map(String::as_str)
        .collect();

    // Prefer standard source dirs; fall back to any code file when the repo
    // has no conventional src/ layout (e.g. pure E2E automation repos).
    let source_file_paths: Vec<&str> = {
        let standard: Vec<&str> = tree.iter()
            .filter(|p| is_source_file(p))
            .take(MAX_SOURCE_FILES)
            .map(String::as_str)
            .collect();
        if standard.is_empty() {
            tree.iter()
                .filter(|p| is_code_file(p) && !is_test_file(p))
                .take(MAX_SOURCE_FILES)
                .map(String::as_str)
                .collect()
        } else {
            standard
        }
    };

    let test_file_paths: Vec<&str> = tree.iter()
        .filter(|p| is_test_file(p))
        .take(MAX_TEST_FILES)
        .map(String::as_str)
        .collect();

    // Fetch file contents (sequential to avoid rate-limits), applying compression in-place
    let mut stack_files = Vec::new();
    for path in &stack_file_paths {
        if let Ok(f) = fetch_file_content(&client, api_base, &project_id, token, &default_branch, path).await {
            if !f.content.is_empty() {
                stack_files.push(GitLabFile {
                    path: f.path,
                    content: truncate_to_lines(&f.content, MAX_STACK_FILE_LINES),
                });
            }
        }
    }

    let mut source_files = Vec::new();
    for path in &source_file_paths {
        if let Ok(f) = fetch_file_content(&client, api_base, &project_id, token, &default_branch, path).await {
            if !f.content.is_empty() {
                let (compressed, _) = apply_compression(&f.content, &f.path, filter_level, max_lines_per_file, false);
                source_files.push(GitLabFile { path: f.path, content: compressed });
            }
        }
    }

    let mut test_files = Vec::new();
    for path in &test_file_paths {
        if let Ok(f) = fetch_file_content(&client, api_base, &project_id, token, &default_branch, path).await {
            if !f.content.is_empty() {
                // Test files always use Minimal (preserve assertion patterns)
                let (compressed, _) = apply_compression(&f.content, &f.path, filter_level, max_lines_per_file, true);
                test_files.push(GitLabFile { path: f.path, content: compressed });
            }
        }
    }

    let tech_stack = detect_stack(&tree, &stack_files);

    // Compress the file tree to avoid sending thousands of raw paths
    let (compressed_tree, _) = compress_file_tree(&tree, MAX_FILE_TREE_ENTRIES);

    Ok(GitLabRepoContext {
        project_path,
        repo_url: repo_url.to_string(),
        default_branch,
        description,
        tech_stack,
        file_tree: compressed_tree,
        stack_files,
        source_files,
        test_files,
    })
}
