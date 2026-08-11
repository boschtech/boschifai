use boschifai_core::models::{Requirement, TestCase, TestStep};
use thiserror::Error;

#[derive(Debug, Error)]
pub enum AIError {
    #[error("failed to connect to AI service: {0}")]
    ConnectionError(String),
    #[error("AI service returned an invalid response: {0}")]
    ParseError(String),
    #[error("AI service request failed: {0}")]
    RequestError(String),
}

/// Generates test cases from a given requirement using an AI model.
///
/// Currently returns hardcoded sample test cases. In a future iteration,
/// this will make a real API call to an AI service.
pub async fn generate_tests_from_requirement(
    requirement: &Requirement,
) -> Result<Vec<TestCase>, AIError> {
    // Placeholder: return hardcoded sample test cases
    let test_cases = vec![
        TestCase {
            id: format!("TC-{}-001", requirement.id),
            title: format!("Verify basic functionality of {}", requirement.title),
            steps: vec![
                TestStep::Simple("Given the system is in its default state".to_string()),
                TestStep::Simple(format!("When the user triggers '{}'", requirement.title)),
                TestStep::Simple("Then the expected outcome is observed".to_string()),
            ],
            expected_result: format!(
                "The requirement '{}' is fulfilled successfully",
                requirement.title
            ),
            description: None,
            precondition: None,
            automation_type: None,
            priority: None,
            status: None,
            assignee: None,
            reporter: None,
            components: None,
            labels: None,
        },
        TestCase {
            id: format!("TC-{}-002", requirement.id),
            title: format!("Verify error handling for {}", requirement.title),
            steps: vec![
                TestStep::Simple("Given the system is in its default state".to_string()),
                TestStep::Simple("When invalid input is provided".to_string()),
                TestStep::Simple("Then the system handles the error gracefully".to_string()),
            ],
            expected_result: "An appropriate error message is displayed and the system remains stable".to_string(),
            description: None,
            precondition: None,
            automation_type: None,
            priority: None,
            status: None,
            assignee: None,
            reporter: None,
            components: None,
            labels: None,
        },
    ];

    Ok(test_cases)
}
