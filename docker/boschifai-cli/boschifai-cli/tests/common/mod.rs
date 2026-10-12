#![allow(dead_code)] // each integration-test binary uses a different subset of these helpers

use std::fs;
use std::io::{BufRead, BufReader, Write};
use std::net::{Shutdown, TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};
use std::time::Duration;

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

/// A local HTTP server on a random port that answers every request with whatever
/// `respond(path)` returns as (status, JSON body). It keeps serving for the rest of the test
/// process, so a client that retries or reconnects is still answered.
///
/// Each response is followed by a graceful close — shut down our sending side, then read until
/// the client closes. Dropping the socket straight after writing (the original version) passed
/// on macOS but failed on Linux CI: an abrupt close there can discard the response before the
/// client reads it, and the client then hangs until its own timeout.
pub fn fake_http_server<F>(respond: F) -> String
where
    F: Fn(&str) -> (u16, String) + Send + 'static,
{
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let base_url = format!("http://{}", listener.local_addr().unwrap());

    std::thread::spawn(move || {
        for stream in listener.incoming() {
            let Ok(stream) = stream else { continue };
            let _ = serve_one(stream, &respond);
        }
    });

    base_url
}

fn serve_one<F>(mut stream: TcpStream, respond: &F) -> std::io::Result<()>
where
    F: Fn(&str) -> (u16, String),
{
    let mut reader = BufReader::new(stream.try_clone()?);

    let mut request_line = String::new();
    reader.read_line(&mut request_line)?;
    let path = request_line
        .split_whitespace()
        .nth(1)
        .unwrap_or("/")
        .to_string();

    // Headers end at the first empty line (GET requests have no body).
    let mut header = String::new();
    loop {
        header.clear();
        if reader.read_line(&mut header)? == 0 || header == "\r\n" {
            break;
        }
    }

    let (status, body) = respond(&path);
    let response = format!(
        "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
        body.len()
    );
    stream.write_all(response.as_bytes())?;
    stream.flush()?;

    // Graceful close: tell the client we're done, then wait for it to hang up.
    stream.shutdown(Shutdown::Write)?;
    stream.set_read_timeout(Some(Duration::from_secs(5)))?;
    let _ = std::io::copy(&mut reader, &mut std::io::sink());

    Ok(())
}
