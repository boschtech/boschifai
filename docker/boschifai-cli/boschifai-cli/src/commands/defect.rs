pub async fn generate(file_path: &str, json: bool) {
    if json {
        println!(
            "{}",
            serde_json::json!({
                "command": "defect generate",
                "source": file_path,
                "output": format!("defect_report_{}.md", sanitize(file_path))
            })
        );
    } else {
        println!("Generating defect report from: {}", file_path);
        println!("  Output: defect_report_{}.md", sanitize(file_path));
    }
}

fn sanitize(path: &str) -> String {
    std::path::Path::new(path)
        .file_stem()
        .and_then(|s| s.to_str())
        .unwrap_or("output")
        .to_string()
}
