use crate::integrations::qmetry;
use boschifai_core::models::TestCase;
use std::io::Read;

pub async fn publish(
    file: Option<&str>,
    folder_url: Option<&str>,
    api_key: Option<&str>,
    json: bool,
) {
    let raw = read_input(file);
    let test_cases = parse_test_cases(&raw);

    if test_cases.is_empty() {
        eprintln!("Error: no test cases found in input.");
        std::process::exit(1);
    }

    // Parse folder URL → extract project_id and folder_id
    let (project_id, folder_id) = match folder_url {
        Some(url) => match qmetry::parse_folder_url(url) {
            Ok((fid, pid)) => (Some(pid), Some(fid)),
            Err(e) => {
                eprintln!("Error: {e}");
                std::process::exit(1);
            }
        },
        None => (None, None), // load_config falls back to BOSCHIFAI_QMETRY_PROJECT_ID / BOSCHIFAI_QMETRY_FOLDER_ID env vars
    };

    if !json {
        let project_label = project_id
            .map(|id| id.to_string())
            .unwrap_or_else(|| "(from env)".to_string());
        let folder_label = folder_id
            .map(|id| format!(" (folder ID: {id})"))
            .unwrap_or_default();
        println!(
            "Publishing {} test case(s) to QMetry project {project_label}{folder_label}...",
            test_cases.len(),
        );
    }

    let config = match qmetry::load_config(project_id, folder_id, api_key) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("Error: {e}");
            std::process::exit(1);
        }
    };

    match qmetry::push_test_cases(&config, &test_cases).await {
        Ok(created) => {
            let keys: Vec<&str> = created.iter().map(|c| c.key.as_str()).collect();
            if json {
                println!(
                    "{}",
                    serde_json::to_string_pretty(&serde_json::json!({
                        "published": created.len(),
                        "keys": keys,
                    }))
                    .unwrap()
                );
            } else {
                println!("Published {} test case(s) to QMetry: {}", created.len(), keys.join(", "));
            }
        }
        Err(e) => {
            eprintln!("Error: {e}");
            std::process::exit(1);
        }
    }
}

fn read_input(file: Option<&str>) -> String {
    match file {
        Some(path) => std::fs::read_to_string(path).unwrap_or_else(|e| {
            eprintln!("Error reading file '{path}': {e}");
            std::process::exit(1);
        }),
        None => {
            let mut buf = String::new();
            std::io::stdin().read_to_string(&mut buf).unwrap_or_else(|e| {
                eprintln!("Error reading stdin: {e}");
                std::process::exit(1);
            });
            buf
        }
    }
}

fn parse_test_cases(raw: &str) -> Vec<TestCase> {
    let value: serde_json::Value = serde_json::from_str(raw).unwrap_or_else(|e| {
        eprintln!("Error: input is not valid JSON: {e}");
        std::process::exit(1);
    });

    let array = if value.is_array() {
        value
    } else if let Some(arr) = value.get("test_cases") {
        arr.clone()
    } else {
        eprintln!("Error: expected a JSON array of test cases or an object with a \"test_cases\" key.");
        std::process::exit(1);
    };

    serde_json::from_value(array).unwrap_or_else(|e| {
        eprintln!("Error: failed to parse test cases: {e}");
        std::process::exit(1);
    })
}
