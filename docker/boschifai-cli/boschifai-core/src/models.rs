use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Requirement {
    pub id: String,
    pub title: String,
    pub description: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TestStrategy {
    pub id: String,
    pub requirement_id: String,
    pub approach: String,
}

/// A single test step — accepts either a plain string or a structured object.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(untagged)]
pub enum TestStep {
    Simple(String),
    Detailed {
        #[serde(rename = "stepDetails")]
        step_details: String,
        #[serde(rename = "testData", default, skip_serializing_if = "Option::is_none")]
        test_data: Option<String>,
        #[serde(rename = "expectedResult", default, skip_serializing_if = "Option::is_none")]
        expected_result: Option<String>,
    },
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TestCase {
    pub id: String,
    pub title: String,
    pub steps: Vec<TestStep>,
    pub expected_result: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub description: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub precondition: Option<String>,
    /// "manual" (default) | "automation_candidate" | "automated"
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub automation_type: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub priority: Option<u64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub status: Option<u64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub assignee: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub reporter: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub components: Option<Vec<u64>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub labels: Option<Vec<u64>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TestReport {
    pub title: String,
    pub summary: String,
    pub test_results: Vec<TestResult>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TestResult {
    pub test_case_id: String,
    pub status: String,
    pub duration_ms: u64,
}

// --- GitLab integration models ---

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct GitLabFile {
    pub path: String,
    pub content: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct GitLabRepoContext {
    pub project_path: String,
    pub repo_url: String,
    pub default_branch: String,
    pub description: Option<String>,
    pub tech_stack: Vec<String>,
    pub file_tree: Vec<String>,
    pub stack_files: Vec<GitLabFile>,
    pub source_files: Vec<GitLabFile>,
    pub test_files: Vec<GitLabFile>,
}

// --- Figma integration models ---

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FigmaFrame {
    pub id: String,
    pub name: String,
    pub page: String,
    pub node_type: String,
    pub children_names: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FigmaComponent {
    pub id: String,
    pub name: String,
    pub description: String,
    pub variants: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FigmaStyle {
    pub id: String,
    pub name: String,
    pub style_type: String,
    pub description: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FigmaImageExport {
    pub frame_id: String,
    pub frame_name: String,
    pub url: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FigmaContext {
    pub file_key: String,
    pub file_name: String,
    pub pages: Vec<String>,
    pub frames: Vec<FigmaFrame>,
    pub components: Vec<FigmaComponent>,
    pub styles: Vec<FigmaStyle>,
    pub image_exports: Vec<FigmaImageExport>,
}

// --- Jira integration models ---

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct JiraIssueSource {
    pub key: String,
    pub issue_type: String,
    pub summary: String,
    pub description: String,
    pub labels: Vec<String>,
    pub status: String,
    pub assignee: Option<String>,
    pub story_points: Option<f64>,
    pub epic_link: Option<String>,
}

impl JiraIssueSource {
    /// Convert a Jira issue into a Requirement for use in review/strategy/test-case flows.
    pub fn to_requirement(&self) -> Requirement {
        Requirement {
            id: self.key.clone(),
            title: self.summary.clone(),
            description: self.description.clone(),
        }
    }
}
