# Companies House Director Risk Checker

Built by [Phil Atkins](https://phillipatkins.co.uk)

---

Before signing a contract, do you actually know who you're dealing with?

Directors of UK companies are public record. So is every company they've ever been involved in — including the ones that dissolved, went into liquidation, or entered administration. Most people never check. This tool does it automatically.

Give it a company name, it pulls every active director from Companies House, fetches their full appointment history, flags any dissolved or liquidated companies, and gives each director a risk rating — **LOW**, **MEDIUM**, or **HIGH**.

I ran it on Tesco PLC as a test. 12 directors. Three came back HIGH — one with 4 dissolved companies, one with a company in liquidation. All publicly available information.

---

## What it does

- Searches Companies House for the company you specify
- Pulls all active directors
- For each director, fetches their complete appointment history across every company they've ever been on
- Flags any companies in their history that are dissolved, in liquidation, in administration, or in receivership
- Assigns a risk rating: LOW (none), MEDIUM (1 minor issue), HIGH (2+ or any liquidation/administration)
- Prints a colour-coded terminal report
- Exports a CSV report

---

## Setup

**1. Get a free Companies House API key**

Register at https://developer.company-information.service.gov.uk — it's free, takes 2 minutes.

**2. Install dependencies**

```bash
pip install -r requirements.txt
```

**3. Set your API key**

```bash
cp .env.example .env
# Edit .env and paste your key
```

---

## Usage

```bash
python checker.py "Tesco PLC"
python checker.py "Acme Supplies Ltd"
python checker.py  # prompts if you don't pass a name
```

Select from the search results, then it runs the full check.

---

## Output

Terminal output is colour-coded — red for HIGH, yellow for MEDIUM, green for LOW. Shows each director's name, total appointment count, and any flagged companies with their status.

A CSV report is saved in the current directory named `{CompanyName}_risk_report.csv`.

---

## Useful for

- Onboarding a new supplier
- Signing a service contract
- Hiring a contractor or consultant
- Any B2B due diligence

---

## Notes

- Uses the free [Companies House REST API](https://developer.company-information.service.gov.uk/api/rest-api)
- No data is stored or sent anywhere other than Companies House
- Rate limits apply on the free tier — if you hit a 429, wait a moment

---

MIT License — Phil Atkins 2026 — [phillipatkins.co.uk](https://phillipatkins.co.uk)
