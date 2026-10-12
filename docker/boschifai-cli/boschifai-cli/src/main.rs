mod commands;
mod integrations;

use clap::{ArgAction, Parser, Subcommand};

#[derive(Parser)]
#[command(
    name = "boschifai",
    about = "Boschifai - Software Quality Lifecycle Management"
)]
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
}

// ── Action enums ─────────────────────────────────────────────────────────────

const JIRA_ENV_HELP: &str = "\
Environment variables (required for --jira-key):
  BOSCHIFAI_JIRA_BASE_URL   Jira Server/DC base URL (e.g. https://jira.example.com)
  BOSCHIFAI_JIRA_TOKEN      Pre-encoded Base64 token (base64 of username:api_token)

Optional:
  BOSCHIFAI_JIRA_ALLOWED_TYPES       Comma-separated list of accepted issue types [default: Epic,Story,Feature]
  BOSCHIFAI_JIRA_STORY_POINTS_FIELD  Custom field ID for story points [default: customfield_10028]
  BOSCHIFAI_JIRA_EPIC_LINK_FIELD     Custom field ID for epic link [default: customfield_10014]
  BOSCHIFAI_JIRA_TIMEOUT_MS          Request timeout in milliseconds [default: 10000]";

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
    #[command(after_help = JIRA_ENV_HELP)]
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
    /// Generate unit tests for a source file
    Unit {
        /// Path to a local source file
        #[arg(long)]
        file: String,
    },
    /// Generate end-to-end tests for a feature
    E2e {
        /// Path to a local requirements or feature file
        #[arg(long)]
        file: String,
    },
    /// Generate a component with tests and stories from a file
    Component {
        /// Path to a local spec or requirements file
        #[arg(long)]
        file: String,
    },
    /// Generate an API contract from a requirements file
    #[command(name = "api-contract")]
    ApiContract {
        /// Path to a local requirements or OpenAPI file
        #[arg(long)]
        file: String,
    },
    /// Generate performance tests for an endpoint or feature
    Performance {
        /// Path to a local requirements or API spec file
        #[arg(long)]
        file: String,
    },
    /// Generate Storybook stories for a component from a file
    Storybook {
        /// Path to a local component source file
        #[arg(long)]
        file: String,
    },
}

// ── Main dispatch ────────────────────────────────────────────────────────────

#[tokio::main]
async fn main() {
    let cli = Cli::parse();
    let json = cli.json;

    match cli.command {
        Commands::Requirements { action } => match action {
            RequirementsAction::Review {
                file_path,
                jira_key,
            } => {
                commands::requirements::review(file_path.as_deref(), jira_key.as_deref(), json)
                    .await;
            }
        },
        Commands::Strategy { action } => match action {
            StrategyAction::Create {
                file,
                requirement,
                jira_key,
            } => {
                let source = file.or(requirement);
                commands::strategy::create(source.as_deref(), jira_key.as_deref(), json).await;
            }
        },
        Commands::TestCase { action } => match action {
            TestCaseAction::Generate {
                file,
                requirement,
                jira_key,
            } => {
                let source = file.or(requirement);
                commands::test_case::generate(source.as_deref(), jira_key.as_deref(), json).await;
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
        Commands::Gen { action } => {
            let (target, file) = match action {
                GenAction::Unit { file } => (commands::gen::GenTarget::Unit, file),
                GenAction::E2e { file } => (commands::gen::GenTarget::E2e, file),
                GenAction::Component { file } => (commands::gen::GenTarget::Component, file),
                GenAction::ApiContract { file } => (commands::gen::GenTarget::ApiContract, file),
                GenAction::Performance { file } => (commands::gen::GenTarget::Performance, file),
                GenAction::Storybook { file } => (commands::gen::GenTarget::Storybook, file),
            };
            commands::gen::run(target, &file, json);
        }
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
    }
}
