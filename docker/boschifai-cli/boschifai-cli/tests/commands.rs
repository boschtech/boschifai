//! End-to-end checks of every subcommand's arguments and output, run against the real binary.

mod common;

use common::*;
use serde_json::Value;

fn json_of(args: &[&str]) -> Value {
    let output = run(boschifai().arg("--json").args(args));
    assert!(output.status.success(), "{args:?}: {}", stderr(&output));
    serde_json::from_str(&stdout(&output))
        .unwrap_or_else(|e| panic!("{args:?}: {e}\n{}", stdout(&output)))
}

#[test]
fn help_lists_only_the_supported_commands() {
    let help = stdout(&run(boschifai().arg("--help")));

    for command in [
        "requirements",
        "strategy",
        "test-case",
        "plan",
        "data",
        "defect",
        "traceability",
        "testability",
        "gen",
        "report",
        "init",
    ] {
        assert!(help.contains(command), "missing {command}");
    }
    for removed in ["qmetry", "figma", "api-test", "api-contract"] {
        assert!(
            !help.to_lowercase().contains(removed),
            "{removed} should be gone"
        );
    }
}

#[test]
fn removed_integrations_are_rejected() {
    assert!(!run(boschifai().args(["qmetry", "publish"]))
        .status
        .success());
    assert!(!run(boschifai().args(["figma"])).status.success());
    assert!(
        !run(boschifai().args(["gen", "unit", "--repo-url", "https://gitlab.com/x"]))
            .status
            .success()
    );
    assert!(!run(boschifai().args([
        "test-case",
        "generate",
        "--requirement",
        "R",
        "--qmetry-folder-url",
        "x"
    ]))
    .status
    .success());
    assert!(
        !run(boschifai().args(["api-test", "generate", "--file", "api/openapi.yaml"]))
            .status
            .success()
    );
    assert!(
        !run(boschifai().args(["gen", "api-contract", "--file", "api/openapi.yaml"]))
            .status
            .success()
    );
}

#[test]
fn gen_names_the_output_after_the_source_file() {
    for (target, prefix, label) in [
        ("unit", "unit_tests", "unit tests"),
        ("e2e", "e2e_tests", "E2E tests"),
        ("component", "component", "component"),
        ("performance", "perf_tests", "performance tests"),
        ("storybook", "storybook", "Storybook stories"),
    ] {
        let value = json_of(&["gen", target, "--file", "src/My Payment Service.php"]);

        assert_eq!(value["command"], format!("gen {label}"));
        assert_eq!(value["output"], format!("{prefix}_my_payment_service.md"));
        assert_eq!(value["source"], "src/My Payment Service.php");
    }
}

#[test]
fn gen_prints_a_human_readable_summary() {
    let out = stdout(&run(boschifai().args([
        "gen",
        "component",
        "--file",
        "app/RefundService.php",
    ])));

    assert!(out.contains("Generating component from: app/RefundService.php"));
    assert!(out.contains("Output: component_refundservice.md"));
}

#[test]
fn gen_requires_a_file() {
    let output = run(boschifai().args(["gen", "unit"]));

    assert!(!output.status.success());
    assert!(stderr(&output).contains("--file"));
}

#[test]
fn json_output_can_be_enabled_from_the_environment() {
    let output = run(boschifai()
        .args(["gen", "unit", "--file", "a.ts"])
        .env("BOSCHIFAI_JSON", "true"));

    assert!(serde_json::from_str::<Value>(&stdout(&output)).is_ok());
}

#[test]
fn file_based_document_commands_report_their_output_file() {
    for (args, command, output) in [
        (
            vec!["plan", "generate", "--file", "docs/refunds.md"],
            "plan generate",
            "test_plan_refunds.md",
        ),
        (
            vec!["data", "generate", "--file", "docs/refunds.md"],
            "data generate",
            "test_data_refunds.md",
        ),
        (
            vec!["traceability", "generate", "--file", "docs/refunds.md"],
            "traceability generate",
            "traceability_refunds.md",
        ),
        (
            vec!["testability", "review", "--file", "docs/refunds.md"],
            "testability review",
            "testability_review_refunds.md",
        ),
        (
            vec!["defect", "generate", "--file", "results/junit.xml"],
            "defect generate",
            "defect_report_junit.md",
        ),
    ] {
        let value = json_of(&args);

        assert_eq!(value["command"], command, "{args:?}");
        assert_eq!(value["output"], output, "{args:?}");
    }
}

#[test]
fn document_commands_print_a_summary_without_json() {
    for (args, expected) in [
        (
            vec!["plan", "generate", "--file", "docs/r.md"],
            "Generating test plan from: docs/r.md",
        ),
        (
            vec!["data", "generate", "--file", "docs/r.md"],
            "Generating test data specification from: docs/r.md",
        ),
        (
            vec!["traceability", "generate", "--file", "docs/r.md"],
            "Generating traceability matrix from: docs/r.md",
        ),
        (
            vec!["testability", "review", "--file", "docs/r.md"],
            "Reviewing testability of: docs/r.md",
        ),
        (
            vec!["defect", "generate", "--file", "docs/r.md"],
            "Generating defect report from: docs/r.md",
        ),
        (
            vec!["requirements", "review", "docs/r.md"],
            "Reviewing requirements from: docs/r.md",
        ),
        (
            vec!["strategy", "create", "--requirement", "REQ-9"],
            "Creating test strategy for requirement: REQ-9",
        ),
        (
            vec!["report", "generate", "--input", "results.xml"],
            "Generating report from: results.xml",
        ),
    ] {
        let output = run(boschifai().args(&args));
        assert!(output.status.success(), "{args:?}: {}", stderr(&output));
        assert!(
            stdout(&output).contains(expected),
            "{args:?}: {}",
            stdout(&output)
        );
    }
}

#[test]
fn source_commands_need_exactly_one_source() {
    for command in [
        vec!["plan", "generate"],
        vec!["data", "generate"],
        vec!["traceability", "generate"],
        vec!["testability", "review"],
        vec!["test-case", "generate"],
        vec!["strategy", "create"],
        vec!["requirements", "review"],
    ] {
        let neither = run(boschifai().args(&command));
        assert_eq!(neither.status.code(), Some(1), "{command:?} with no source");
        assert!(
            stderr(&neither).contains("provide"),
            "{command:?}: {}",
            stderr(&neither)
        );

        let source_flag = match command[0] {
            "test-case" | "strategy" => vec!["--requirement", "REQ-1"],
            "requirements" => vec!["docs/r.md"],
            _ => vec!["--file", "docs/r.md"],
        };
        let both = run(boschifai()
            .args(&command)
            .args(&source_flag)
            .args(["--jira-key", "AIR-1"]));
        assert_eq!(both.status.code(), Some(1), "{command:?} with both sources");
        assert!(
            stderr(&both).contains("not both"),
            "{command:?}: {}",
            stderr(&both)
        );
    }
}

#[test]
fn test_case_generate_returns_structured_cases() {
    let cases = json_of(&["test-case", "generate", "--requirement", "REQ-7"]);

    let ids: Vec<&str> = cases
        .as_array()
        .unwrap()
        .iter()
        .map(|c| c["id"].as_str().unwrap())
        .collect();
    assert_eq!(ids, ["TC-REQ-7-001", "TC-REQ-7-002"]);
    assert!(cases[0]["steps"].as_array().unwrap().len() == 3);
}

#[test]
fn test_case_generate_prints_each_case_and_step() {
    let out = stdout(&run(boschifai().args([
        "test-case",
        "generate",
        "--file",
        "REQ-7",
    ])));

    assert!(out.contains("Successfully generated 2 test case(s)"));
    assert!(out.contains("[TC-REQ-7-001]"));
    assert!(out.contains("Step 1: Given the system is in its default state"));
    assert!(out.contains("Expected:"));
}

#[test]
fn strategy_requirements_and_report_return_json() {
    let strategy = json_of(&["strategy", "create", "--file", "REQ-3"]);
    assert_eq!(strategy["id"], "TS-REQ-3");
    assert_eq!(strategy["requirement_id"], "REQ-3");

    let requirements = json_of(&["requirements", "review", "docs/refunds.md"]);
    assert_eq!(requirements[0]["id"], "REQ-001");

    let report = json_of(&["report", "generate", "--input", "results.xml"]);
    assert_eq!(report["title"], "Test Report from results.xml");
    assert_eq!(report["test_results"].as_array().unwrap().len(), 2);
}
