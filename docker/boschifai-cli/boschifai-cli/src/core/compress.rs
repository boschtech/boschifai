use std::collections::BTreeMap;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum FilterLevel {
    None,
    #[default]
    Minimal,
    Aggressive,
}

impl std::str::FromStr for FilterLevel {
    type Err = String;
    fn from_str(s: &str) -> Result<Self, Self::Err> {
        match s.to_lowercase().as_str() {
            "none" => Ok(Self::None),
            "minimal" => Ok(Self::Minimal),
            "aggressive" => Ok(Self::Aggressive),
            other => Err(format!(
                "Unknown filter level '{other}'. Use: none, minimal, aggressive"
            )),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Language {
    Java,
    Rust,
    JavaScript,
    TypeScript,
    Python,
    Go,
    /// JSON, YAML, TOML, XML, lock files, Markdown — never stripped
    Data,
    Unknown,
}

pub fn detect_language(path: &str) -> Language {
    let ext = path.to_lowercase();
    let ext = ext.rsplit('.').next().unwrap_or("");
    match ext {
        "java" => Language::Java,
        "rs" => Language::Rust,
        "js" | "jsx" | "mjs" | "cjs" => Language::JavaScript,
        "ts" | "tsx" => Language::TypeScript,
        "py" => Language::Python,
        "go" => Language::Go,
        "json" | "yaml" | "yml" | "toml" | "xml" | "lock" | "csv" | "md" | "txt" => Language::Data,
        _ => Language::Unknown,
    }
}

/// Apply language-aware content filtering to reduce token count.
/// `Language::Data` and `Language::Unknown` are never modified.
pub fn filter_source(content: &str, lang: Language, level: FilterLevel) -> String {
    match level {
        FilterLevel::None => content.to_string(),
        FilterLevel::Minimal => filter_minimal(content, lang),
        FilterLevel::Aggressive => filter_aggressive(&filter_minimal(content, lang), lang),
    }
}

fn filter_minimal(content: &str, lang: Language) -> String {
    if matches!(lang, Language::Data | Language::Unknown) {
        return content.to_string();
    }

    let mut out: Vec<&str> = Vec::new();
    let mut blank_run = 0usize;
    let mut in_block_comment = false;

    for line in content.lines() {
        let t = line.trim();

        if matches!(
            lang,
            Language::Java | Language::JavaScript | Language::TypeScript | Language::Rust | Language::Go
        ) {
            if in_block_comment {
                if t.contains("*/") {
                    in_block_comment = false;
                }
                continue;
            }
            // Non-doc block comment opening (/* but not /**)
            if t.starts_with("/*") && !t.starts_with("/**") {
                if !t.contains("*/") {
                    in_block_comment = true;
                }
                continue;
            }
            // Single-line comment (but preserve doc comments ///)
            if t.starts_with("//") && !t.starts_with("///") {
                continue;
            }
        }

        if matches!(lang, Language::Python) && t.starts_with('#') {
            continue;
        }

        if t.is_empty() {
            blank_run += 1;
            if blank_run <= 1 {
                out.push("");
            }
            continue;
        }
        blank_run = 0;
        out.push(line);
    }

    out.join("\n")
}

/// Count net brace delta on a line, skipping string literals and // comments.
fn count_brace_delta(line: &str) -> i32 {
    let chars: Vec<char> = line.chars().collect();
    let len = chars.len();
    let mut delta = 0i32;
    let mut in_str = false;
    let mut str_char = '"';
    let mut i = 0;
    while i < len {
        let c = chars[i];
        if in_str {
            if c == '\\' && i + 1 < len {
                i += 2;
                continue;
            } else if c == str_char {
                in_str = false;
            }
        } else {
            // Stop counting after // line comments
            if c == '/' && i + 1 < len && chars[i + 1] == '/' {
                break;
            }
            match c {
                '"' | '\'' | '`' => {
                    in_str = true;
                    str_char = c;
                }
                '{' => delta += 1,
                '}' => delta -= 1,
                _ => {}
            }
        }
        i += 1;
    }
    delta
}

fn filter_aggressive(content: &str, lang: Language) -> String {
    // Python has no braces; minimal-only is the best we can do
    if matches!(lang, Language::Python | Language::Data | Language::Unknown) {
        return content.to_string();
    }

    // Class-based code: method bodies start at depth 2 (inside class + method).
    // Module-level code (e.g. Go, TypeScript functions without a class):
    //   function bodies start at depth 1.
    let has_class = content.lines().any(|l| {
        let t = l.trim();
        t.starts_with("class ")
            || t.contains(" class ")
            || t.starts_with("export class ")
            || t.starts_with("abstract class ")
            || t.starts_with("impl ")
            || (matches!(lang, Language::Java) && t.contains(" interface "))
            || (matches!(lang, Language::Java) && t.contains(" enum "))
    });
    let body_threshold: i32 = if has_class { 2 } else { 1 };

    let mut out: Vec<String> = Vec::new();
    let mut depth: i32 = 0;
    let mut placeholder_written = false;

    for line in content.lines() {
        let t = line.trim();
        let net = count_brace_delta(line);
        let is_closing = t.starts_with('}');
        let depth_after = (depth + net).max(0);

        let should_keep = if is_closing {
            depth_after < body_threshold
        } else {
            depth < body_threshold
        };

        if should_keep {
            out.push(line.to_string());
            placeholder_written = false;
        } else if !placeholder_written {
            let indent: String = line.chars().take_while(|c| c.is_whitespace()).collect();
            out.push(format!("{indent}// ... implementation"));
            placeholder_written = true;
        }

        depth = depth_after;
    }

    out.join("\n")
}

/// Smart truncation: when content exceeds max_lines, prioritise structurally
/// important lines (imports, declarations, signatures) before body content.
/// Original line order is preserved in the output.
/// Returns `(truncated_content, n_omitted)`.
pub fn smart_truncate(content: &str, max_lines: usize) -> (String, usize) {
    let lines: Vec<&str> = content.lines().collect();
    if lines.len() <= max_lines {
        return (content.to_string(), 0);
    }

    fn score(line: &str) -> u8 {
        let t = line.trim();
        if t.starts_with("import ")
            || t.starts_with("package ")
            || t.starts_with("use ")
            || t.starts_with("from ")
            || t.starts_with("require(")
            || t.starts_with("#include")
        {
            3
        } else if t.contains("class ")
            || t.contains("interface ")
            || t.contains("struct ")
            || t.starts_with("fn ")
            || t.starts_with("def ")
            || t.starts_with("func ")
            || t.starts_with("public ")
            || t.starts_with("private ")
            || t.starts_with("protected ")
            || t.starts_with("export ")
            || t.starts_with("async ")
            || t.starts_with("override ")
            || t.starts_with('@')
        {
            2
        } else {
            1
        }
    }

    let mut indexed: Vec<(usize, u8)> =
        lines.iter().enumerate().map(|(i, l)| (i, score(l))).collect();
    // Sort by score desc, then by position for deterministic output
    indexed.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));

    let mut keep: Vec<usize> = indexed.iter().take(max_lines).map(|(i, _)| *i).collect();
    keep.sort_unstable();

    let omitted = lines.len() - keep.len();
    let result: Vec<&str> = keep.iter().map(|&i| lines[i]).collect();
    (result.join("\n"), omitted)
}

/// Safety guard: if filtered output costs more tokens than raw, return raw.
/// Uses `bytes / 4` as a token estimate (matches RTK's approach).
pub fn never_worse(filtered: String, raw: &str) -> String {
    if filtered.len() / 4 > raw.len() / 4 {
        raw.to_string()
    } else {
        filtered
    }
}

/// Full compression pipeline: filter → never_worse guard → truncation.
/// Returns `(compressed_content, n_truncated_lines)`.
/// For test files, always use `Minimal` filtering to preserve assertion patterns.
pub fn apply_compression(
    content: &str,
    path: &str,
    level: FilterLevel,
    max_lines: usize,
    is_test_file: bool,
) -> (String, usize) {
    let lang = detect_language(path);
    let effective_level = if is_test_file { FilterLevel::Minimal } else { level };

    let filtered = filter_source(content, lang, effective_level);
    let after_filter = never_worse(filtered, content);

    let line_count = after_filter.lines().count();
    if line_count > max_lines {
        let (truncated, omitted) = smart_truncate(&after_filter, max_lines);
        let with_hint = if omitted > 0 {
            format!(
                "{}\n// [{omitted} lines omitted — use --filter-level none to see full content]",
                truncated
            )
        } else {
            truncated
        };
        (with_hint, omitted)
    } else {
        (after_filter, 0)
    }
}

/// Compress a flat file-tree path list into directory-grouped summary lines
/// when it exceeds `max_entries`. Returns `(compressed_lines, summary_string)`.
pub fn compress_file_tree(paths: &[String], max_entries: usize) -> (Vec<String>, String) {
    let total = paths.len();
    if total <= max_entries {
        return (paths.to_vec(), format!("{total} files"));
    }

    let mut by_dir: BTreeMap<String, BTreeMap<String, usize>> = BTreeMap::new();
    for path in paths {
        let (dir, filename) = match path.rfind('/') {
            Some(pos) => (path[..pos].to_string(), &path[pos + 1..]),
            None => (".".to_string(), path.as_str()),
        };
        let ext = match filename.rfind('.') {
            Some(pos) => filename[pos..].to_string(),
            None => "(no ext)".to_string(),
        };
        *by_dir.entry(dir).or_default().entry(ext).or_default() += 1;
    }

    let mut lines: Vec<String> = by_dir
        .iter()
        .map(|(dir, exts)| {
            let ext_parts: Vec<String> =
                exts.iter().map(|(ext, n)| format!("{ext} ×{n}")).collect();
            format!("{dir}/  ({})", ext_parts.join(", "))
        })
        .collect();

    let summary = format!(
        "{total} files → {dir_count} directories (--filter-level none for full tree)",
        dir_count = lines.len()
    );
    lines.push(format!("// {summary}"));
    (lines, summary)
}

/// Truncate a file to at most `max_lines`, appending a marker when cut.
pub fn truncate_to_lines(content: &str, max_lines: usize) -> String {
    let lines: Vec<&str> = content.lines().collect();
    if lines.len() <= max_lines {
        return content.to_string();
    }
    let omitted = lines.len() - max_lines;
    format!(
        "{}\n// ... {omitted} lines omitted",
        lines[..max_lines].join("\n")
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_minimal_strips_single_line_comments() {
        let java = "// comment\npublic class Foo {}";
        let result = filter_minimal(java, Language::Java);
        assert!(!result.contains("// comment"), "should strip // comments");
        assert!(result.contains("public class Foo"), "should keep code");
    }

    #[test]
    fn test_minimal_preserves_doc_comments() {
        let java = "/** doc */\npublic void foo() {}";
        let result = filter_minimal(java, Language::Java);
        assert!(result.contains("/** doc */"), "should keep Javadoc");
    }

    #[test]
    fn test_minimal_strips_block_comments() {
        let java = "/* regular comment */\npublic class Foo {}";
        let result = filter_minimal(java, Language::Java);
        assert!(!result.contains("regular comment"), "should strip /* comments");
        assert!(result.contains("public class Foo"), "should keep code");
    }

    #[test]
    fn test_minimal_collapses_blank_lines() {
        let code = "line1\n\n\n\nline2";
        let result = filter_minimal(code, Language::Java);
        assert!(!result.contains("\n\n\n"), "should collapse 3+ blank lines");
        assert!(result.contains("line1"));
        assert!(result.contains("line2"));
    }

    #[test]
    fn test_data_language_never_stripped() {
        let json = "{\n  // not a comment\n  \"key\": \"value\"\n}";
        let result = filter_source(json, Language::Data, FilterLevel::Aggressive);
        assert_eq!(result, json, "Data language must never be modified");
    }

    #[test]
    fn test_aggressive_strips_method_bodies_java() {
        let java = "public class Foo {\n    public String getBar() {\n        String result = \"hello\";\n        return result;\n    }\n}";
        let result = filter_aggressive(java, Language::Java);
        assert!(result.contains("public String getBar()"), "should keep signature");
        assert!(!result.contains("String result"), "should strip body");
        assert!(result.contains("// ... implementation"), "should add placeholder");
    }

    #[test]
    fn test_aggressive_preserves_field_declarations() {
        let java = "public class Foo {\n    private final String field;\n    public void method() {\n        doWork();\n    }\n}";
        let result = filter_aggressive(java, Language::Java);
        assert!(result.contains("private final String field"), "should keep field");
        assert!(!result.contains("doWork()"), "should strip method body");
    }

    #[test]
    fn test_never_worse_returns_raw_when_filtered_larger() {
        let raw = "a";
        let filtered = "a".repeat(100);
        let result = never_worse(filtered, raw);
        assert_eq!(result, raw, "should return raw when filtered is larger");
    }

    #[test]
    fn test_smart_truncate_keeps_imports_over_body() {
        let mut lines: Vec<String> = (0..5).map(|i| format!("import foo.Class{i};")).collect();
        lines.extend((5..200).map(|i| format!("    doSomething({});", i)));
        let content = lines.join("\n");
        let (truncated, omitted) = smart_truncate(&content, 10);
        assert!(omitted > 0, "should omit some lines");
        assert!(truncated.contains("import"), "should keep imports");
    }

    #[test]
    fn test_filter_level_from_str() {
        assert_eq!("none".parse::<FilterLevel>().unwrap(), FilterLevel::None);
        assert_eq!("minimal".parse::<FilterLevel>().unwrap(), FilterLevel::Minimal);
        assert_eq!("aggressive".parse::<FilterLevel>().unwrap(), FilterLevel::Aggressive);
        assert!("invalid".parse::<FilterLevel>().is_err());
    }

    #[test]
    fn test_compress_file_tree_groups_when_over_limit() {
        let paths: Vec<String> = vec![
            "src/main/java/Foo.java".into(),
            "src/main/java/Bar.java".into(),
            "src/test/java/FooTest.java".into(),
        ];
        let (compressed, summary) = compress_file_tree(&paths, 2);
        assert!(compressed.iter().any(|l| l.contains("src/main/java")));
        assert!(summary.contains("3 files"));
    }

    #[test]
    fn test_compress_file_tree_passthrough_when_under_limit() {
        let paths: Vec<String> = vec!["src/Foo.java".into(), "src/Bar.java".into()];
        let (result, _) = compress_file_tree(&paths, 300);
        assert_eq!(result, paths, "should return paths unchanged when under limit");
    }
}
