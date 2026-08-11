use boschifai_core::models::{FigmaComponent, FigmaContext, FigmaFrame, FigmaImageExport, FigmaStyle};
use serde_json::Value;
use std::env;

#[derive(Debug)]
pub enum FigmaError {
    MissingToken,
    InvalidUrl(String),
    HttpError(String),
    AuthError(String),
    NotFound(String),
    ParseError(String),
}

impl std::fmt::Display for FigmaError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            FigmaError::MissingToken => write!(
                f,
                "Missing BOSCHIFAI_FIGMA_TOKEN.\n\
                 Set BOSCHIFAI_FIGMA_TOKEN to a Figma personal access token.\n\
                 Obtain one from Figma → Account Settings → Personal access tokens."
            ),
            FigmaError::InvalidUrl(url) => write!(
                f,
                "Cannot extract Figma file key from URL: {url}\n\
                 Expected format: https://www.figma.com/file/<key>/... or https://www.figma.com/design/<key>/..."
            ),
            FigmaError::HttpError(msg) => write!(f, "Figma API error: {msg}"),
            FigmaError::AuthError(msg) => write!(f, "Figma authentication error: {msg}"),
            FigmaError::NotFound(path) => write!(f, "Figma resource not found: {path}"),
            FigmaError::ParseError(msg) => write!(f, "Failed to parse Figma response: {msg}"),
        }
    }
}

pub struct FigmaFetchOptions {
    pub export_images: bool,
    pub page_filter: Option<String>,
}

impl Default for FigmaFetchOptions {
    fn default() -> Self {
        Self {
            export_images: false,
            page_filter: None,
        }
    }
}

fn load_token() -> Result<String, FigmaError> {
    match env::var("BOSCHIFAI_FIGMA_TOKEN") {
        Ok(t) if t.trim().is_empty() => Err(FigmaError::MissingToken),
        Ok(t) => Ok(t.trim().to_string()),
        Err(_) => Err(FigmaError::MissingToken),
    }
}

/// Extract the file key from a Figma URL.
/// Supports both /file/<key>/... and /design/<key>/... formats.
pub fn parse_file_key(url: &str) -> Result<String, FigmaError> {
    let url = url.trim().trim_end_matches('/');

    let segments: Vec<&str> = url.split('/').collect();
    for (i, seg) in segments.iter().enumerate() {
        if (*seg == "file" || *seg == "design") && i + 1 < segments.len() {
            let key = segments[i + 1];
            if !key.is_empty() {
                return Ok(key.to_string());
            }
        }
    }

    Err(FigmaError::InvalidUrl(url.to_string()))
}

fn timeout_ms() -> u64 {
    env::var("BOSCHIFAI_FIGMA_TIMEOUT_MS")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(30_000)
}

async fn get_json(
    client: &reqwest::Client,
    url: &str,
    token: &str,
) -> Result<Value, FigmaError> {
    let response = client
        .get(url)
        .header("X-Figma-Token", token)
        .header("Accept", "application/json")
        .timeout(std::time::Duration::from_millis(timeout_ms()))
        .send()
        .await
        .map_err(|e| FigmaError::HttpError(e.to_string()))?;

    let status = response.status();
    if status.as_u16() == 404 {
        return Err(FigmaError::NotFound(url.to_string()));
    }
    if status.as_u16() == 401 || status.as_u16() == 403 {
        return Err(FigmaError::AuthError(format!(
            "HTTP {status}. Check BOSCHIFAI_FIGMA_TOKEN is valid and has access to this file."
        )));
    }
    if !status.is_success() {
        return Err(FigmaError::HttpError(format!("HTTP {status} for {url}")));
    }

    response
        .json()
        .await
        .map_err(|e| FigmaError::ParseError(e.to_string()))
}

/// Walk a Figma node tree and collect top-level frames from a page (CANVAS node).
fn collect_frames(canvas_name: &str, canvas: &Value) -> Vec<FigmaFrame> {
    let mut frames = Vec::new();
    let children = canvas.get("children").and_then(|v| v.as_array());
    if let Some(children) = children {
        for child in children {
            let node_type = child
                .get("type")
                .and_then(|v| v.as_str())
                .unwrap_or("UNKNOWN")
                .to_string();
            let id = child
                .get("id")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .to_string();
            let name = child
                .get("name")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .to_string();

            let children_names: Vec<String> = child
                .get("children")
                .and_then(|v| v.as_array())
                .map(|arr| {
                    arr.iter()
                        .filter_map(|n| n.get("name").and_then(|v| v.as_str()).map(String::from))
                        .collect()
                })
                .unwrap_or_default();

            frames.push(FigmaFrame {
                id,
                name,
                page: canvas_name.to_string(),
                node_type,
                children_names,
            });
        }
    }
    frames
}

/// Fetch and analyse a Figma file, returning structured design context for AI generation.
pub async fn fetch_figma_context(
    file_key: &str,
    options: FigmaFetchOptions,
) -> Result<FigmaContext, FigmaError> {
    let token = load_token()?;
    let client = reqwest::Client::new();

    // 1. Fetch the file document (pages, frames, node tree)
    let file_url = format!("https://api.figma.com/v1/files/{file_key}");
    let file_doc = get_json(&client, &file_url, &token).await?;

    let file_name = file_doc
        .get("name")
        .and_then(|v| v.as_str())
        .unwrap_or("Untitled")
        .to_string();

    let document = file_doc.get("document");
    let canvases = document
        .and_then(|d| d.get("children"))
        .and_then(|v| v.as_array())
        .cloned()
        .unwrap_or_default();

    let mut pages: Vec<String> = Vec::new();
    let mut frames: Vec<FigmaFrame> = Vec::new();

    for canvas in &canvases {
        let page_name = canvas
            .get("name")
            .and_then(|v| v.as_str())
            .unwrap_or("Untitled Page")
            .to_string();

        // Apply page filter if specified
        if let Some(ref filter) = options.page_filter {
            if !page_name.eq_ignore_ascii_case(filter) {
                continue;
            }
        }

        pages.push(page_name.clone());
        frames.extend(collect_frames(&page_name, canvas));
    }

    // 2. Fetch published components
    let components_url = format!("https://api.figma.com/v1/files/{file_key}/components");
    let components_doc = get_json(&client, &components_url, &token).await?;

    let components: Vec<FigmaComponent> = components_doc
        .get("meta")
        .and_then(|m| m.get("components"))
        .and_then(|v| v.as_array())
        .map(|arr| {
            arr.iter()
                .map(|c| {
                    let name = c
                        .get("name")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string();
                    // Variants are implied by "/" in the component name (Figma convention)
                    let variants: Vec<String> = if name.contains('/') {
                        vec![name.split('/').last().unwrap_or("").to_string()]
                    } else {
                        Vec::new()
                    };
                    FigmaComponent {
                        id: c
                            .get("node_id")
                            .and_then(|v| v.as_str())
                            .unwrap_or("")
                            .to_string(),
                        name,
                        description: c
                            .get("description")
                            .and_then(|v| v.as_str())
                            .unwrap_or("")
                            .to_string(),
                        variants,
                    }
                })
                .collect()
        })
        .unwrap_or_default();

    // 3. Fetch styles (design tokens)
    let styles_url = format!("https://api.figma.com/v1/files/{file_key}/styles");
    let styles_doc = get_json(&client, &styles_url, &token).await?;

    let styles: Vec<FigmaStyle> = styles_doc
        .get("meta")
        .and_then(|m| m.get("styles"))
        .and_then(|v| v.as_array())
        .map(|arr| {
            arr.iter()
                .map(|s| FigmaStyle {
                    id: s
                        .get("node_id")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string(),
                    name: s
                        .get("name")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string(),
                    style_type: s
                        .get("style_type")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string(),
                    description: s
                        .get("description")
                        .and_then(|v| v.as_str())
                        .unwrap_or("")
                        .to_string(),
                })
                .collect()
        })
        .unwrap_or_default();

    // 4. Export images (optional)
    let mut image_exports: Vec<FigmaImageExport> = Vec::new();
    if options.export_images && !frames.is_empty() {
        // Collect top-level frame IDs (up to 50 to avoid huge requests)
        let frame_ids: Vec<&FigmaFrame> = frames
            .iter()
            .filter(|f| f.node_type == "FRAME")
            .take(50)
            .collect();

        if !frame_ids.is_empty() {
            let ids_param = frame_ids
                .iter()
                .map(|f| f.id.as_str())
                .collect::<Vec<_>>()
                .join(",");
            let images_url = format!(
                "https://api.figma.com/v1/images/{file_key}?ids={ids_param}&format=png"
            );

            if let Ok(images_doc) = get_json(&client, &images_url, &token).await {
                if let Some(images_map) = images_doc.get("images").and_then(|v| v.as_object()) {
                    for frame in &frame_ids {
                        if let Some(url_val) = images_map.get(&frame.id) {
                            if let Some(url) = url_val.as_str() {
                                image_exports.push(FigmaImageExport {
                                    frame_id: frame.id.clone(),
                                    frame_name: frame.name.clone(),
                                    url: url.to_string(),
                                });
                            }
                        }
                    }
                }
            }
        }
    }

    Ok(FigmaContext {
        file_key: file_key.to_string(),
        file_name,
        pages,
        frames,
        components,
        styles,
        image_exports,
    })
}
