mod commands;
mod core;
mod integrations;

use clap::{ArgAction, Parser, Subcommand};

#[derive(Parser)]
#[command(name = "boschifai", about = "Boschifai - Software Quality Lifecycle Management")]
struct Cli {
    /// Output results as JSON
    #[arg(long, global = true, env = "BOSCHIFAI_JSON", action = ArgAction::SetTrue)]
    json: bool,

    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    /// Analyze and review requirements documents
    Requirements {
        #[command(subcommand)]
        action: RequirementsAction,
    },
    /// Create and manage test strategies
    Strategy {
        #[command(subcommand)]
        action: StrategyAction,
    },
    /// Generate test cases using AI
    #[command(name = "test-case")]
    TestCase {
        #[command(subcommand)]
        action: TestCaseAction,
    },
    /// Generate a test plan
    Plan {
        #[command(subcommand)]
        action: PlanAction,
    },
    /// Generate test data specifications and sample data sets
    Data {
        #[command(subcommand)]
        action: DataAction,
    },
    /// Generate a defect report with root cause analysis
    Defect {
        #[command(subcommand)]
        action: DefectAction,
    },
    /// Generate a requirements traceability matrix
    Traceability {
        #[command(subcommand)]
        action: TraceabilityAction,
    },
    /// Generate API test scenarios and contract tests
    #[command(name = "api-test")]
    ApiTest {
        #[command(subcommand)]
        action: ApiTestAction,
    },
    /// Review requirements for testability
    Testability {
        #[command(subcommand)]
        action: TestabilityAction,
    },
    /// Generate code and test artifacts from source files
    Gen {
        #[command(subcommand)]
        action: GenAction,
    },
    /// Generate reports from test results
    Report {
        #[command(subcommand)]
        action: ReportAction,
    },
    /// Install Boschifai skills and commands into the current project
    Init {
        /// Skip confirmation prompt
        #[arg(short = 'y', long)]
        yes: bool,
        /// Overwrite existing installation
        #[arg(long)]
        force: bool,
    },
    /// Fetch and inspect Figma design files
    Figma {
        #[command(subcommand)]
        action: commands::figma::FigmaAction,
    },
    /// Publish test cases to QMetry Test Management for Jira
    Qmetry {
        #[command(subcommand)]
        action: QMetryAction,
    },
}

// ── QMetry action enum ───────────────────────────────────────────────────────

#[derive(Subcommand)]
enum QMetryAction {
    /// Publish test cases from a JSON file (or stdin) to QMetry
    #[command(after_help = QMETRY_ENV_HELP)]
    Publish {
        /// Path to a JSON file containing test cases (omit to read from stdin)
        #[arg(long)]
        file: Option<String>,
        /// QMetry folder URL copied from the folder browser, e.g.
        /// https://jira.example.com/secure/QTMAction.jspa#/?folderId=97656&projectId=23302
        #[arg(long, env = "BOSCHIFAI_QMETRY_FOLDER_URL")]
        folder_url: Option<String>,
        /// QMetry API key (from QMetry → Administration → API Keys)
        #[arg(long, env = "BOSCHIFAI_QMETRY_TOKEN")]
        api_key: Option<String>,
    },
}

// ── Existing action enums ────────────────────────────────────────────────────

const JIRA_ENV_HELP: &str = "\
Environment variables (required for --jira-key):
  BOSCHIFAI_JIRA_BASE_URL   Jira Server/DC base URL (e.g. https://jira.example.com)
  BOSCHIFAI_JIRA_TOKEN      Pre-encoded Base64 token (base64 of username:api_token)

Optional:
  BOSCHIFAI_JIRA_ALLOWED_TYPES       Comma-separated list of accepted issue types [default: Epic,Story,Feature]
  BOSCHIFAI_JIRA_STORY_POINTS_FIELD  Custom field ID for story points [default: customfield_10028]
  BOSCHIFAI_JIRA_EPIC_LINK_FIELD     Custom field ID for epic link [default: customfield_10014]
  BOSCHIFAI_JIRA_TIMEOUT_MS          Request timeout in milliseconds [default: 10000]";

const GITLAB_ENV_HELP: &str = "\
Environment variables (required for --repo-url):
  BOSCHIFAI_GITLAB_TOKEN   GitLab personal access token with read_repository scope
                    Set to an empty string for public repositories

Optional:
  BOSCHIFAI_GITLAB_TIMEOUT_MS   Request timeout in milliseconds [default: 15000]";

const QMETRY_ENV_HELP: &str = "\
Environment variables:
  BOSCHIFAI_JIRA_BASE_URL      Jira base URL — QMetry runs on the same Jira instance [required]
  BOSCHIFAI_QMETRY_TOKEN       QMetry API key (QMetry → Administration → API Keys) [required]
  BOSCHIFAI_QMETRY_FOLDER_URL  QMetry folder URL — replaces --folder-url / --qmetry-folder-url [optional]
  BOSCHIFAI_QMETRY_PROJECT_ID  Numeric project ID — used when no folder URL is provided [optional]
  BOSCHIFAI_QMETRY_FOLDER_ID   Numeric folder ID — used when no folder URL is provided [optional]
  BOSCHIFAI_QMETRY_TIMEOUT_MS  Request timeout in milliseconds [default: 15000]
  BOSCHIFAI_QMETRY_DEBUG       Set to any value to print request/response details";

const GITLAB_FIGMA_ENV_HELP: &str = "\
Environment variables (required for --repo-url):
  BOSCHIFAI_GITLAB_TOKEN   GitLab personal access token with read_repository scope
                    Set to an empty string for public repositories

Optional:
  BOSCHIFAI_GITLAB_TIMEOUT_MS   Request timeout in milliseconds [default: 15000]

Environment variables (required for --figma-url):
  BOSCHIFAI_FIGMA_TOKEN        Figma personal access token
                        Obtain from Figma → Account Settings → Personal access tokens
  BOSCHIFAI_FIGMA_TIMEOUT_MS   Request timeout in milliseconds [default: 30000]";

#[derive(Subcommand)]
enum RequirementsAction {
    /// Review a requirements document
    #[command(after_help = JIRA_ENV_HELP)]
    Review {
        /// Path to the requirements file
        file_path: Option<String>,
        /// Jira issue key to fetch requirements from (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum StrategyAction {
    /// Create a test strategy for a requirement
    #[command(after_help = JIRA_ENV_HELP)]
    Create {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// The requirement ID to create a strategy for
        #[arg(long)]
        requirement: Option<String>,
        /// Jira issue key to derive strategy from (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum TestCaseAction {
    /// Generate test cases from a requirement
    #[command(after_help = concat!(
        "Environment variables (Jira source):\n\
        \x20 BOSCHIFAI_JIRA_BASE_URL   Jira base URL [required for --jira-key]\n\
        \x20 BOSCHIFAI_JIRA_TOKEN      Base64 of username:api_token [required for --jira-key]\n\
        \n\
        Environment variables (QMetry push):\n\
        \x20 BOSCHIFAI_JIRA_BASE_URL      Jira base URL [required]\n\
        \x20 BOSCHIFAI_QMETRY_TOKEN       QMetry API key [required]\n\
        \x20 BOSCHIFAI_QMETRY_FOLDER_URL  Folder URL — replaces --qmetry-folder-url [optional]\n\
        \x20 BOSCHIFAI_QMETRY_TIMEOUT_MS  Request timeout ms [default: 15000]\n\
        \x20 BOSCHIFAI_QMETRY_DEBUG       Set to any value to print debug info"
    ))]
    Generate {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// The requirement ID to generate tests for
        #[arg(long)]
        requirement: Option<String>,
        /// Jira issue key to generate tests from (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
        /// QMetry folder URL copied from the folder browser, e.g.
        /// https://jira.example.com/secure/QTMAction.jspa#/?folderId=97656&projectId=23302
        #[arg(long, env = "BOSCHIFAI_QMETRY_FOLDER_URL")]
        qmetry_folder_url: Option<String>,
        /// QMetry API key (from QMetry → Administration → API Keys)
        #[arg(long, env = "BOSCHIFAI_QMETRY_TOKEN")]
        qmetry_api_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum ReportAction {
    /// Generate a report from test results
    Generate {
        /// Path to the test results file
        #[arg(long)]
        input: String,
    },
}

// ── New action enums ─────────────────────────────────────────────────────────

#[derive(Subcommand)]
enum PlanAction {
    /// Generate a test plan from requirements
    #[command(after_help = JIRA_ENV_HELP)]
    Generate {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// Jira issue key (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum DataAction {
    /// Generate test data specifications and sample data sets
    #[command(after_help = JIRA_ENV_HELP)]
    Generate {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// Jira issue key (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum DefectAction {
    /// Generate a defect report from test failure information
    Generate {
        /// Path to the test failure / results file
        #[arg(long)]
        file: String,
    },
}

#[derive(Subcommand)]
enum TraceabilityAction {
    /// Generate a requirements traceability matrix
    #[command(after_help = JIRA_ENV_HELP)]
    Generate {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// Jira issue key (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum ApiTestAction {
    /// Generate API test scenarios and contract tests
    #[command(after_help = JIRA_ENV_HELP)]
    Generate {
        /// Path to the API spec or requirements file
        #[arg(long)]
        file: Option<String>,
        /// Jira issue key (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum TestabilityAction {
    /// Review requirements for testability and generate an analysis report
    #[command(after_help = JIRA_ENV_HELP)]
    Review {
        /// Path to the requirements file
        #[arg(long)]
        file: Option<String>,
        /// Jira issue key (e.g. AIR-123)
        #[arg(long)]
        jira_key: Option<String>,
    },
}

#[derive(Subcommand)]
enum GenAction {
    /// Generate unit tests for a source file or GitLab repository
    #[command(after_help = GITLAB_ENV_HELP)]
    Unit {
        /// Path to a local source file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
    /// Generate end-to-end tests for a feature or GitLab repository
    #[command(after_help = GITLAB_FIGMA_ENV_HELP)]
    E2e {
        /// Path to a local requirements or feature file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Figma file URL to use as design context
        #[arg(long)]
        figma_url: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
    /// Generate a component with tests and stories from a file or GitLab repository
    #[command(after_help = GITLAB_FIGMA_ENV_HELP)]
    Component {
        /// Path to a local spec or requirements file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Figma file URL to use as design context
        #[arg(long)]
        figma_url: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
    /// Generate an API contract from a requirements file or GitLab repository
    #[command(name = "api-contract", after_help = GITLAB_ENV_HELP)]
    ApiContract {
        /// Path to a local requirements or OpenAPI file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
    /// Generate performance tests for an endpoint or feature
    #[command(after_help = GITLAB_ENV_HELP)]
    Performance {
        /// Path to a local requirements or API spec file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
    /// Generate Storybook stories for a component from a file or GitLab repository
    #[command(after_help = GITLAB_FIGMA_ENV_HELP)]
    Storybook {
        /// Path to a local component source file
        #[arg(long)]
        file: Option<String>,
        /// GitLab repository URL (e.g. https://gitlab.com/group/project)
        #[arg(long)]
        repo_url: Option<String>,
        /// Target file path within the repository (used with --repo-url)
        #[arg(long)]
        repo_file: Option<String>,
        /// Branch to check out (defaults to the project's default branch, or master if not set)
        #[arg(long)]
        repo_branch: Option<String>,
        /// Figma file URL to use as design context
        #[arg(long)]
        figma_url: Option<String>,
        /// Source file filter level: none | minimal | aggressive (default: minimal)
        #[arg(long, default_value = "minimal")]
        filter_level: String,
        /// Maximum lines per source file sent to Claude (default: 300)
        #[arg(long, default_value = "300")]
        max_lines_per_file: usize,
    },
}

// ── Main dispatch ────────────────────────────────────────────────────────────

#[tokio::main]
async fn main() {
    let cli = Cli::parse();
    let json = cli.json;

    match cli.command {
        Commands::Requirements { action } => match action {
            RequirementsAction::Review { file_path, jira_key } => {
                commands::requirements::review(file_path.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::Strategy { action } => match action {
            StrategyAction::Create { file, requirement, jira_key } => {
                let source = file.or(requirement);
                commands::strategy::create(source.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::TestCase { action } => match action {
            TestCaseAction::Generate { file, requirement, jira_key, qmetry_folder_url, qmetry_api_key } => {
                let source = file.or(requirement);
                commands::test_case::generate(
                    source.as_deref(),
                    jira_key.as_deref(),
                    qmetry_folder_url.as_deref(),
                    qmetry_api_key.as_deref(),
                    json,
                ).await;
            }
        },
        Commands::Plan { action } => match action {
            PlanAction::Generate { file, jira_key } => {
                commands::plan::generate(file.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::Data { action } => match action {
            DataAction::Generate { file, jira_key } => {
                commands::data::generate(file.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::Defect { action } => match action {
            DefectAction::Generate { file } => {
                commands::defect::generate(&file, json).await;
            }
        },
        Commands::Traceability { action } => match action {
            TraceabilityAction::Generate { file, jira_key } => {
                commands::traceability::generate(file.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::ApiTest { action } => match action {
            ApiTestAction::Generate { file, jira_key } => {
                commands::api_test::generate(file.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::Testability { action } => match action {
            TestabilityAction::Review { file, jira_key } => {
                commands::testability::review(file.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::Gen { action } => match action {
            GenAction::Unit { file, repo_url, repo_file, repo_branch, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::Unit,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url: None,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
            GenAction::E2e { file, repo_url, repo_file, repo_branch, figma_url, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::E2e,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
            GenAction::Component { file, repo_url, repo_file, repo_branch, figma_url, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::Component,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
            GenAction::ApiContract { file, repo_url, repo_file, repo_branch, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::ApiContract,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url: None,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
            GenAction::Performance { file, repo_url, repo_file, repo_branch, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::Performance,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url: None,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
            GenAction::Storybook { file, repo_url, repo_file, repo_branch, figma_url, filter_level, max_lines_per_file } => {
                commands::gen::run(
                    commands::gen::GenTarget::Storybook,
                    commands::gen::GenSource {
                        file, repo_url, repo_file, repo_branch, figma_url,
                        filter_level: filter_level.parse().unwrap_or_default(),
                        max_lines_per_file,
                    },
                    json,
                ).await;
            }
        },
        Commands::Report { action } => match action {
            ReportAction::Generate { input } => {
                commands::report::generate(&input, json);
            }
        },
        Commands::Init { yes, force } => {
            if let Err(e) = commands::run_init(yes, force) {
                eprintln!("Error: {e}");
                std::process::exit(1);
            }
        }
        Commands::Figma { action } => {
            commands::figma::run(action, json).await;
        }
        Commands::Qmetry { action } => match action {
            QMetryAction::Publish { file, folder_url, api_key } => {
                commands::qmetry::publish(file.as_deref(), folder_url.as_deref(), api_key.as_deref(), json).await;
            }
        },
    }
}
