//! Prints the analysis of PDF files as JSON and measures timings.
//!
//! Usage: pdf-analyze [--bench] FILE...

use std::time::Instant;

use pdf_preflight::{Document, OpenOptions};

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let bench = args.iter().any(|a| a == "--bench");
    let files: Vec<&String> = args.iter().filter(|a| !a.starts_with("--")).collect();
    if files.is_empty() {
        eprintln!("usage: pdf-analyze [--bench] FILE...");
        std::process::exit(2);
    }

    let mut failed = false;
    for path in files {
        let t0 = Instant::now();
        let doc = match Document::open(path, &OpenOptions::default()) {
            Ok(doc) => doc,
            Err(e) => {
                eprintln!("{path}: error {}: {e}", e.code());
                failed = true;
                continue;
            }
        };
        let opened = t0.elapsed();
        let analysis = doc.analyze_value();
        let total = t0.elapsed();
        if bench {
            println!(
                "{path}\tpages={}\topen={:.3}ms\tanalyze={:.3}ms\ttotal={:.3}ms",
                doc.page_count(),
                opened.as_secs_f64() * 1000.0,
                (total - opened).as_secs_f64() * 1000.0,
                total.as_secs_f64() * 1000.0
            );
        } else {
            println!("{}", analysis.to_json());
        }
    }
    if failed {
        std::process::exit(1);
    }
}
