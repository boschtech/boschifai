#![allow(dead_code)] // each integration-test binary uses a different subset of these helpers

use std::fs;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpListener;
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};

/// The `boschifai` binary built for this test run, with every BOSCHIFAI_* variable from the
/// developer's own shell removed so tests never pick up real Jira credentials or flags.
pub fn boschifai() -> Command {
    let mut cmd = Command::new(env!("CARGO_BIN_EXE_boschifai"));
    for (key, _) in std::env::vars() {
        if key.starts_with("BOSCHIFAI_") {
            cmd.env_remove(key);
        }
    }
    cmd
}

pub fn run(cmd: &mut Command) -> Output {
    cmd.output().expect("failed to run boschifai")
}

pub fn run_with_stdin(cmd: &mut Command, stdin: &str) -> Output {
    let mut child = cmd
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .expect("failed to spawn boschifai");
    child
        .stdin
        .take()
        .unwrap()
        .write_all(stdin.as_bytes())
        .unwrap();
    child.wait_with_output().unwrap()
}

pub fn stdout(output: &Output) -> String {
    String::from_utf8_lossy(&output.stdout).into_owned()
}

pub fn stderr(output: &Output) -> String {
    String::from_utf8_lossy(&output.stderr).into_owned()
}

/// The real templates shipped in this repository (docker/boschifai-cli/templates).
pub fn templates_source() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("../templates")
}

pub fn copy_dir(from: &Path, to: &Path) {
    fs::create_dir_all(to).unwrap();
    for entry in fs::read_dir(from).unwrap() {
        let entry = entry.unwrap();
        let target = to.join(entry.file_name());
        if entry.file_type().unwrap().is_dir() {
            copy_dir(&entry.path(), &target);
        } else {
            fs::copy(entry.path(), target).unwrap();
        }
    }
}

/// Every file under `dir`, as paths relative to it, sorted.
pub fn files_under(dir: &Path) -> Vec<String> {
    let mut out = Vec::new();
    collect(dir, dir, &mut out);
    out.sort();
    out
}

fn collect(root: &Path, dir: &Path, out: &mut Vec<String>) {
    for entry in fs::read_dir(dir).unwrap() {
        let path = entry.unwrap().path();
        if path.is_dir() {
            collect(root, &path, out);
        } else if path.file_name().unwrap() != ".DS_Store" {
            out.push(
                path.strip_prefix(root)
                    .unwrap()
                    .to_string_lossy()
                    .replace('\\', "/"),
            );
        }
    }
}

/// A one-thread HTTP server on a random local port that answers each request with whatever
/// `respond(path)` returns as (status, JSON body). Serves `requests` requests, then stops.
pub fn fake_http_server<F>(requests: usize, respond: F) -> String
where
    F: Fn(&str) -> (u16, String) + Send + 'static,
{
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let base_url = format!("http://{}", listener.local_addr().unwrap());

    std::thread::spawn(move || {
        for stream in listener.incoming().take(requests) {
            let mut stream = stream.unwrap();
            let mut reader = BufReader::new(stream.try_clone().unwrap());

            let mut request_line = String::new();
            reader.read_line(&mut request_line).unwrap();
            let path = request_line
                .split_whitespace()
                .nth(1)
                .unwrap_or("/")
                .to_string();

            // Drain the headers (GET requests have no body).
            let mut header = String::new();
            while reader.read_line(&mut header).unwrap() > 2 {
                header.clear();
            }
            let _ = reader.get_mut().read(&mut [0u8; 0]);

            let (status, body) = respond(&path);
            let response = format!(
                "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                body.len()
            );
            stream.write_all(response.as_bytes()).unwrap();
        }
    });

    base_url
}
