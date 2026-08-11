use crate::integrations::figma::{self, FigmaFetchOptions};
use clap::Subcommand;

const FIGMA_ENV_HELP: &str = "\
Environment variables:
  BOSCHIFAI_FIGMA_TOKEN        Figma personal access token [required]
                        Obtain from Figma → Account Settings → Personal access tokens
  BOSCHIFAI_FIGMA_TIMEOUT_MS   Request timeout in milliseconds [default: 30000]";

#[derive(Subcommand)]
pub enum FigmaAction {
    /// Fetch design context from a Figma file
    #[command(after_help = FIGMA_ENV_HELP)]
    Fetch {
        /// Figma file URL — supports both /file/ and /design/ formats
        /// (e.g. https://www.figma.com/design/ABC123/My-Design)
        #[arg(long)]
        figma_url: String,
        /// Filter to a specific page by name
        #[arg(long)]
        page: Option<String>,
        /// Export PNG images for top-level frames (returns CDN URLs)
        #[arg(long, default_value_t = false)]
        export_images: bool,
    },
}

pub async fn run(action: FigmaAction, json: bool) {
    match action {
        FigmaAction::Fetch {
            figma_url,
            page,
            export_images,
        } => {
            let file_key = match figma::parse_file_key(&figma_url) {
                Ok(k) => k,
                Err(e) => {
                    if json {
                        println!("{}", serde_json::json!({ "error": e.to_string() }));
                    } else {
                        eprintln!("Error: {e}");
                    }
                    std::process::exit(1);
                }
            };

            let options = FigmaFetchOptions {
                export_images,
                page_filter: page,
            };

            match figma::fetch_figma_context(&file_key, options).await {
                Ok(ctx) => {
                    if json {
                        println!("{}", serde_json::to_string_pretty(&ctx).unwrap_or_default());
                    } else {
                        println!("Figma file: {}", ctx.file_name);
                        println!("  File key:   {}", ctx.file_key);
                        println!("  Pages:      {}", ctx.pages.join(", "));
                        println!("  Frames:     {}", ctx.frames.len());
                        println!("  Components: {}", ctx.components.len());
                        println!("  Styles:     {}", ctx.styles.len());
                        if !ctx.image_exports.is_empty() {
                            println!("  Image exports ({}):", ctx.image_exports.len());
                            for img in &ctx.image_exports {
                                println!("    [{}] {} → {}", img.frame_id, img.frame_name, img.url);
                            }
                        }
                    }
                }
                Err(e) => {
                    if json {
                        println!("{}", serde_json::json!({ "error": e.to_string() }));
                    } else {
                        eprintln!("Figma error: {e}");
                    }
                    std::process::exit(1);
                }
            }
        }
    }
}
