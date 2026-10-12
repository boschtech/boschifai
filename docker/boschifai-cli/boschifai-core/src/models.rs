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
        #[serde(
            rename = "expectedResult",
            default,
            skip_serializing_if = "Option::is_none"
        )]
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn test_step_accepts_a_plain_string() {
        let step: TestStep = serde_json::from_value(json!("Open the refunds page")).unwrap();

        assert!(matches!(step, TestStep::Simple(ref s) if s == "Open the refunds page"));
    }

    #[test]
    fn test_step_accepts_a_detailed_object_with_camel_case_fields() {
        let step: TestStep = serde_json::from_value(json!({
            "stepDetails": "Submit a refund",
            "testData": "amount=10",
            "expectedResult": "Refund created"
        }))
        .unwrap();

        match step {
            TestStep::Detailed {
                step_details,
                test_data,
                expected_result,
            } => {
                assert_eq!(step_details, "Submit a refund");
                assert_eq!(test_data.as_deref(), Some("amount=10"));
                assert_eq!(expected_result.as_deref(), Some("Refund created"));
            }
            other => panic!("expected a detailed step, got {other:?}"),
        }
    }

    #[test]
    fn detailed_step_omits_missing_optional_fields_when_serialized() {
        let step = TestStep::Detailed {
            step_details: "Submit".into(),
            test_data: None,
            expected_result: None,
        };

        assert_eq!(
            serde_json::to_value(step).unwrap(),
            json!({ "stepDetails": "Submit" })
        );
    }

    #[test]
    fn test_case_round_trips_and_omits_unset_optional_fields() {
        let input = json!({
            "id": "TC-1",
            "title": "Refund",
            "steps": ["Given", { "stepDetails": "When" }],
            "expected_result": "Then",
            "priority": 2
        });

        let case: TestCase = serde_json::from_value(input.clone()).unwrap();

        assert_eq!(case.priority, Some(2));
        assert!(case.description.is_none());
        assert_eq!(serde_json::to_value(&case).unwrap(), input);
    }

    #[test]
    fn jira_issue_converts_to_a_requirement() {
        let issue = JiraIssueSource {
            key: "AIR-1".into(),
            issue_type: "Story".into(),
            summary: "Refunds".into(),
            description: "As a user…".into(),
            labels: vec![],
            status: "Open".into(),
            assignee: None,
            story_points: None,
            epic_link: None,
        };

        let requirement = issue.to_requirement();

        assert_eq!(requirement.id, "AIR-1");
        assert_eq!(requirement.title, "Refunds");
        assert_eq!(requirement.description, "As a user…");
    }
}
