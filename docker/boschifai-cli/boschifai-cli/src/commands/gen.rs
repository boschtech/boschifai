use crate::core::compress::FilterLevel;
use crate::integrations::{figma, figma::FigmaFetchOptions, gitlab};

pub enum GenTarget {
    Unit,
    E2e,
    Component,
    ApiContract,
    Performance,
    Storybook,
}

pub struct GenSource {
    pub file: Option<String>,
    pub repo_url: Option<String>,
    pub repo_file: Option<String>,
    pub repo_branch: Option<String>,
    pub figma_url: Option<String>,
    pub filter_level: FilterLevel,
    pub max_lines_per_file: usize,
}

pub async fn run(target: GenTarget, source: GenSource, json: bool) {
    let (label, prefix) = match target {
        GenTarget::Unit => ("unit tests", "unit_tests"),
        GenTarget::E2e => ("E2E tests", "e2e_tests"),
        GenTarget::Component => ("component", "component"),
        GenTarget::ApiContract => ("API contract", "api_contract"),
        GenTarget::Performance => ("performance tests", "perf_tests"),
        GenTarget::Storybook => ("Storybook stories", "storybook"),
    };

    let figma_supported = matches!(
        target,
        GenTarget::E2e | GenTarget::Component | GenTarget::Storybook
    );

    // Validate: need at least one source
    if source.file.is_none() && source.repo_url.is_none() && source.figma_url.is_none() {
        if json {
            println!(
                "{}",
                serde_json::json!({
                    "error": "Provide --file <path>, --repo-url <url>, or --figma-url <url>"
                })
            );
        } else {
            eprintln!("Error: provide --file <path>, --repo-url <url>, or --figma-url <url>");
        }
        std::process::exit(1);
    }

    // Fetch GitLab context if requested
    let gitlab_ctx = if let Some(ref url) = source.repo_url {
        match gitlab::fetch_repo_context(url, source.repo_branch.as_deref(), source.filter_level, source.max_lines_per_file).await {
            Ok(ctx) => Some(ctx),
            Err(e) => {
                if json {
                    println!("{}", serde_json::json!({ "error": e.to_string() }));
                } else {
                    eprintln!("GitLab error: {e}");
                }
                std::process::exit(1);
            }
        }
    } else {
        None
    };

    // Fetch Figma context if requested and target supports it
    let figma_ctx = if let Some(ref url) = source.figma_url {
        if figma_supported {
            let file_key = match figma::parse_file_key(url) {
                Ok(k) => k,
                Err(e) => {
                    if json {
                        println!("{}", serde_json::json!({ "error": e.to_string() }));
                    } else {
                        eprintln!("Figma error: {e}");
                    }
                    std::process::exit(1);
                }
            };
            match figma::fetch_figma_context(&file_key, FigmaFetchOptions::default()).await {
                Ok(ctx) => Some(ctx),
                Err(e) => {
                    if json {
                        println!("{}", serde_json::json!({ "error": e.to_string() }));
                    } else {
                        eprintln!("Figma error: {e}");
                    }
                    std::process::exit(1);
                }
            }
        } else {
            eprintln!("Warning: --figma-url is not used for this gen target; ignoring.");
            None
        }
    } else {
        None
    };

    // Determine output stem
    let stem = if let Some(ref ctx) = gitlab_ctx {
        source
            .repo_file
            .as_deref()
            .map(sanitize)
            .or_else(|| ctx.project_path.rsplit('/').next().map(String::from))
            .unwrap_or_else(|| "repo".to_string())
    } else if let Some(ref ctx) = figma_ctx {
        sanitize(&ctx.file_name)
    } else {
        source.file.as_deref().map(sanitize).unwrap_or_else(|| "output".to_string())
    };

    if json {
        let mut payload = serde_json::json!({
            "command": format!("gen {}", label),
            "output": format!("{}_{}.md", prefix, stem),
        });

        if let Some(ref ctx) = gitlab_ctx {
            payload["source"] = serde_json::json!("gitlab");
            payload["repo_url"] = serde_json::Value::String(source.repo_url.clone().unwrap_or_default());
            payload["repo_file"] = serde_json::json!(source.repo_file);
            payload["gitlab_context"] = serde_json::json!(ctx);
        }
        if let Some(ref ctx) = figma_ctx {
            payload["figma_context"] = serde_json::json!(ctx);
        }
        if gitlab_ctx.is_none() && figma_ctx.is_none() {
            payload["source"] = serde_json::Value::String(
                source.file.clone().unwrap_or_default(),
            );
        }

        println!("{}", payload);
    } else {
        if let Some(ref ctx) = gitlab_ctx {
            println!("Generating {} from GitLab repo: {}", label, source.repo_url.as_deref().unwrap_or(""));
            println!("  Project:        {}", ctx.project_path);
            println!("  Branch:         {}", ctx.default_branch);
            println!("  Tech stack:     {}", ctx.tech_stack.join(", "));
            println!("  Files indexed:  {}", ctx.file_tree.len());
            println!("  Source files:   {}", ctx.source_files.len());
            println!("  Test files:     {}", ctx.test_files.len());
            if let Some(ref rf) = source.repo_file {
                println!("  Target file:    {}", rf);
            }
        }
        if let Some(ref ctx) = figma_ctx {
            println!("Figma design context:");
            println!("  File:       {}", ctx.file_name);
            println!("  Pages:      {}", ctx.pages.join(", "));
            println!("  Frames:     {}", ctx.frames.len());
            println!("  Components: {}", ctx.components.len());
            println!("  Styles:     {}", ctx.styles.len());
        }
        if gitlab_ctx.is_none() && figma_ctx.is_none() {
            println!(
                "Generating {} from: {}",
                label,
                source.file.as_deref().unwrap_or("")
            );
        }
        println!("  Output: {}_{}.md", prefix, stem);
    }
}

fn sanitize(path: &str) -> String {
    std::path::Path::new(path)
        .file_stem()
        .and_then(|s| s.to_str())
        .unwrap_or("output")
        .replace(' ', "_")
        .to_lowercase()
}
