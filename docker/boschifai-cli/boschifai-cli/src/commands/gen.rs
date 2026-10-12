pub enum GenTarget {
    Unit,
    E2e,
    Component,
    ApiContract,
    Performance,
    Storybook,
}

pub fn run(target: GenTarget, file: &str, json: bool) {
    let (label, prefix) = match target {
        GenTarget::Unit => ("unit tests", "unit_tests"),
        GenTarget::E2e => ("E2E tests", "e2e_tests"),
        GenTarget::Component => ("component", "component"),
        GenTarget::ApiContract => ("API contract", "api_contract"),
        GenTarget::Performance => ("performance tests", "perf_tests"),
        GenTarget::Storybook => ("Storybook stories", "storybook"),
    };

    let output = format!("{}_{}.md", prefix, sanitize(file));

    if json {
        println!(
            "{}",
            serde_json::json!({
                "command": format!("gen {}", label),
                "output": output,
                "source": file,
            })
        );
    } else {
        println!("Generating {} from: {}", label, file);
        println!("  Output: {}", output);
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
