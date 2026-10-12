//! The Jira integration, exercised through the real CLI against a local fake Jira server.

mod common;

use common::*;
use serde_json::{json, Value};

fn issue(issue_type: &str) -> String {
    json!({
        "key": "AIR-42",
        "fields": {
            "issuetype": { "name": issue_type },
            "summary": "Partial refunds",
            "description": "As a customer\nI want partial refunds",
            "labels": ["payments", "q4"],
            "status": { "name": "In Progress" },
            "assignee": { "displayName": "Dev One" },
            "customfield_10028": 5.0,
            "customfield_10014": "AIR-1"
        }
    })
    .to_string()
}

/// Runs `args` with Jira pointed at a fake server that answers once with (status, body).
fn with_jira(status: u16, body: String, args: &[&str]) -> std::process::Output {
    let base_url = fake_http_server(move |path| {
        assert_eq!(path, "/rest/api/2/issue/AIR-42");
        (status, body.clone())
    });

    run(boschifai()
        .args(args)
        .env("BOSCHIFAI_JIRA_BASE_URL", format!("{base_url}/"))
        .env("BOSCHIFAI_JIRA_TOKEN", "dXNlcjpwYXNz"))
}

#[test]
fn reviews_a_jira_issue() {
    let output = with_jira(
        200,
        issue("Story"),
        &["--json", "requirements", "review", "--jira-key", "AIR-42"],
    );

    assert!(output.status.success(), "{}", stderr(&output));
    let value: Value = serde_json::from_str(&stdout(&output)).unwrap();
    assert_eq!(value["key"], "AIR-42");
    assert_eq!(value["issue_type"], "Story");
    assert_eq!(value["summary"], "Partial refunds");
    assert_eq!(value["labels"], json!(["payments", "q4"]));
    assert_eq!(value["status"], "In Progress");
    assert_eq!(value["assignee"], "Dev One");
    assert_eq!(value["story_points"], 5.0);
    assert_eq!(value["epic_link"], "AIR-1");
}

#[test]
fn prints_a_readable_issue_summary() {
    let out = stdout(&with_jira(
        200,
        issue("Epic"),
        &["requirements", "review", "--jira-key", "AIR-42"],
    ));

    assert!(out.contains("Reviewing requirements from Jira: AIR-42"));
    assert!(out.contains("Assignee:    Dev One"));
    assert!(out.contains("Epic:        AIR-1"));
    assert!(out.contains("Story Pts:   5"));
    assert!(out.contains("Labels:      payments, q4"));
    assert!(out.contains("As a customer\n  I want partial refunds"));
}

#[test]
fn issue_type_check_is_case_insensitive() {
    assert!(with_jira(
        200,
        issue("feature"),
        &["requirements", "review", "--jira-key", "AIR-42"]
    )
    .status
    .success());
}

#[test]
fn every_jira_backed_command_uses_the_issue() {
    for (args, expected) in [
        (vec!["plan", "generate"], "test_plan_air-42.md"),
        (vec!["data", "generate"], "test_data_air-42.md"),
        (vec!["traceability", "generate"], "traceability_air-42.md"),
        (vec!["api-test", "generate"], "api_tests_air-42.md"),
        (
            vec!["testability", "review"],
            "testability_review_air-42.md",
        ),
    ] {
        let mut full = vec!["--json"];
        full.extend(&args);
        full.extend(["--jira-key", "AIR-42"]);

        let output = with_jira(200, issue("Story"), &full);
        assert!(output.status.success(), "{args:?}: {}", stderr(&output));
        let value: Value = serde_json::from_str(&stdout(&output)).unwrap();
        assert_eq!(value["output"], expected, "{args:?}");
        assert_eq!(value["summary"], "Partial refunds", "{args:?}");

        let mut plain = args.clone();
        plain.extend(["--jira-key", "AIR-42"]);
        let text = stdout(&with_jira(200, issue("Story"), &plain));
        assert!(
            text.contains("AIR-42 (Partial refunds)"),
            "{args:?}: {text}"
        );
    }
}

#[test]
fn test_cases_and_strategy_can_come_from_jira() {
    let cases = stdout(&with_jira(
        200,
        issue("Story"),
        &["--json", "test-case", "generate", "--jira-key", "AIR-42"],
    ));
    assert!(cases.contains("TC-AIR-42-001"));

    let text = stdout(&with_jira(
        200,
        issue("Story"),
        &["test-case", "generate", "--jira-key", "AIR-42"],
    ));
    assert!(text.contains("Generating test cases from Jira issue: AIR-42 (Partial refunds)"));

    let strategy = stdout(&with_jira(
        200,
        issue("Story"),
        &["--json", "strategy", "create", "--jira-key", "AIR-42"],
    ));
    assert!(strategy.contains("AIR-42"));

    let strategy_text = stdout(&with_jira(
        200,
        issue("Story"),
        &["strategy", "create", "--jira-key", "AIR-42"],
    ));
    assert!(strategy_text.contains("AIR-42"));
}

#[test]
fn rejects_unsupported_issue_types() {
    let output = with_jira(
        200,
        issue("Bug"),
        &["requirements", "review", "--jira-key", "AIR-42"],
    );

    assert_eq!(output.status.code(), Some(1));
    assert!(stderr(&output).contains("Issue AIR-42 has type \"Bug\" which is not supported."));
}

#[test]
fn allowed_issue_types_can_be_overridden() {
    let base_url = fake_http_server(|_| (200, issue("Bug")));

    let output = run(boschifai()
        .args(["requirements", "review", "--jira-key", "AIR-42"])
        .env("BOSCHIFAI_JIRA_BASE_URL", base_url)
        .env("BOSCHIFAI_JIRA_TOKEN", "x")
        .env("BOSCHIFAI_JIRA_ALLOWED_TYPES", "Story, Bug"));

    assert!(output.status.success(), "{}", stderr(&output));
}

#[test]
fn custom_field_ids_can_be_overridden() {
    let body = json!({
        "key": "AIR-42",
        "fields": { "issuetype": { "name": "Story" }, "customfield_1": 8, "customfield_2": "AIR-9" }
    })
    .to_string();
    let base_url = fake_http_server(move |_| (200, body.clone()));

    let output = run(boschifai()
        .args(["--json", "requirements", "review", "--jira-key", "AIR-42"])
        .env("BOSCHIFAI_JIRA_BASE_URL", base_url)
        .env("BOSCHIFAI_JIRA_TOKEN", "x")
        .env("BOSCHIFAI_JIRA_STORY_POINTS_FIELD", "customfield_1")
        .env("BOSCHIFAI_JIRA_EPIC_LINK_FIELD", "customfield_2"));

    let value: Value = serde_json::from_str(&stdout(&output)).unwrap();
    assert_eq!(value["story_points"], 8.0);
    assert_eq!(value["epic_link"], "AIR-9");
    assert_eq!(value["summary"], "");
    assert_eq!(value["assignee"], Value::Null);
}

#[test]
fn explains_http_failures() {
    for (status, expected) in [
        (404, "Jira issue not found: AIR-42"),
        (401, "Authentication failed (HTTP 401"),
        (403, "Authentication failed (HTTP 403"),
        (500, "Jira API error: HTTP 500"),
    ] {
        let output = with_jira(
            status,
            "{}".into(),
            &["requirements", "review", "--jira-key", "AIR-42"],
        );

        assert_eq!(output.status.code(), Some(1), "HTTP {status}");
        assert!(
            stderr(&output).contains(expected),
            "HTTP {status}: {}",
            stderr(&output)
        );
    }
}

#[test]
fn explains_unparseable_responses() {
    let not_json = with_jira(
        200,
        "<html>".into(),
        &["requirements", "review", "--jira-key", "AIR-42"],
    );
    assert!(stderr(&not_json).contains("Failed to parse Jira response"));

    let no_fields = with_jira(
        200,
        "{}".into(),
        &["requirements", "review", "--jira-key", "AIR-42"],
    );
    assert!(stderr(&no_fields).contains("missing 'fields' in response"));
}

#[test]
fn explains_an_unreachable_jira() {
    let output = run(boschifai()
        .args(["requirements", "review", "--jira-key", "AIR-42"])
        .env("BOSCHIFAI_JIRA_BASE_URL", "http://127.0.0.1:1")
        .env("BOSCHIFAI_JIRA_TOKEN", "x"));

    assert_eq!(output.status.code(), Some(1));
    assert!(stderr(&output).contains("Jira API error"));
}

#[test]
fn every_jira_backed_command_reports_missing_configuration() {
    for args in [
        vec!["requirements", "review"],
        vec!["plan", "generate"],
        vec!["data", "generate"],
        vec!["traceability", "generate"],
        vec!["api-test", "generate"],
        vec!["testability", "review"],
        vec!["test-case", "generate"],
        vec!["strategy", "create"],
    ] {
        let missing_url = run(boschifai().args(&args).args(["--jira-key", "AIR-42"]));
        assert_eq!(missing_url.status.code(), Some(1), "{args:?}");
        assert!(
            stderr(&missing_url)
                .contains("Missing required environment variable: BOSCHIFAI_JIRA_BASE_URL"),
            "{args:?}"
        );
    }

    let missing_token = run(boschifai()
        .args(["plan", "generate", "--jira-key", "AIR-42"])
        .env("BOSCHIFAI_JIRA_BASE_URL", "http://127.0.0.1:1"));
    assert!(stderr(&missing_token)
        .contains("Missing required environment variable: BOSCHIFAI_JIRA_TOKEN"));
}
