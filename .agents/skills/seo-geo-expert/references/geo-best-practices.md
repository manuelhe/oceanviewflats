# Generative Engine Optimization (GEO) Best Practices

Use these guidelines when generating a plan to optimize content for Large Language Models, AI Search Engines (Perplexity, ChatGPT, Claude), and Google Search Generative Experience (SGE).

## 1. Comprehensive Structured Data (JSON-LD)
*   LLMs rely heavily on schema.org JSON-LD to explicitly understand entities without guessing.
*   Identify the exact Schema.org types relevant to the page (e.g., `SoftwareApplication`, `Article`, `VacationRental`, `FAQPage`, `Person`, `Organization`).
*   Nest schemas logically (e.g., An `Organization` that is the `publisher` of an `Article`).

## 2. Entity-Rich Content
*   LLMs map relationships between entities. Explicitly define what the page is about using clear, unambiguous terminology.
*   Use standard industry terms rather than clever jargon.
*   Clearly state the "Who, What, Where, When, and Why" early in the content.

## 3. Conversational & Long-Tail Optimization
*   Generative engines answer conversational questions (e.g., "What are the best beachfront rentals in Santa Marta for digital nomads?").
*   Format content to directly answer these questions. Use FAQ sections (`<h2>` or `<h3>` as the question, followed by a direct, concise paragraph answer).

## 4. Citation and Fact-Checking Readiness
*   AI engines prioritize authoritative, verifiable claims to avoid hallucinations.
*   Include clear statistics, dates, and references.
*   Provide a clear "About the Author" or "About the Company" section to establish EEAT (Experience, Expertise, Authoritativeness, and Trustworthiness).

## 5. Easy Extraction Formatting
*   LLMs prefer lists, tables, and bullet points over dense paragraphs for extracting comparisons, specs, and features.
*   If presenting data, use standard HTML `<table>` structures.
