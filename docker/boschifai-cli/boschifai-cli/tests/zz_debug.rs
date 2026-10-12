mod common;
use common::*;
#[tokio::test]
async fn debug_fake_server() {
    let base = fake_http_server(1, |_| (200, "{\"ok\":true}".into()));
    match reqwest::Client::new().get(format!("{base}/x")).send().await {
        Ok(r) => println!("OK status={} body={:?}", r.status(), r.text().await),
        Err(e) => {
            println!("ERR {e}");
            let mut src: Option<&dyn std::error::Error> = std::error::Error::source(&e);
            while let Some(s) = src { println!("  caused by: {s}  [{s:?}]"); src = s.source(); }
        }
    }
}
