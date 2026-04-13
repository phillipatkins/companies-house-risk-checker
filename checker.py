import os
import sys
import csv
import requests
from colorama import Fore, Style, init
from dotenv import load_dotenv

load_dotenv()

BASE_URL = 'https://api.company-information.service.gov.uk'

BAD_STATUSES = {
    'dissolved', 'liquidation', 'administration',
    'receivership', 'voluntary-arrangement', 'insolvency-proceedings'
}

HEAVY_STATUSES = {'liquidation', 'administration', 'receivership'}


def get_api_key():
    key = os.getenv('COMPANIES_HOUSE_API_KEY', '').strip()
    if not key:
        print(Fore.RED + "\nCOMPANIES_HOUSE_API_KEY not set.")
        print("Copy .env.example to .env and add your free API key.")
        print("Get one at: https://developer.company-information.service.gov.uk\n")
        sys.exit(1)
    return key


def api_get(path, api_key, params=None):
    try:
        r = requests.get(
            f'{BASE_URL}{path}',
            params=params,
            auth=(api_key, ''),
            timeout=10
        )
        if r.status_code == 429:
            print(Fore.YELLOW + "Rate limited — wait a moment and try again.")
            sys.exit(1)
        if r.status_code == 404:
            return None
        r.raise_for_status()
        return r.json()
    except requests.ConnectionError:
        print(Fore.RED + "Connection error — check your internet connection.")
        sys.exit(1)
    except requests.HTTPError as e:
        print(Fore.RED + f"API error: {e}")
        return None


def search_companies(name, api_key):
    data = api_get('/search/companies', api_key, params={'q': name, 'items_per_page': 5})
    return data.get('items', []) if data else []


def get_active_directors(company_number, api_key):
    data = api_get(f'/company/{company_number}/officers', api_key)
    if not data:
        return []
    return [
        o for o in data.get('items', [])
        if 'director' in o.get('officer_role', '').lower()
        and not o.get('resigned_on')
    ]


def get_appointments(officer_id, api_key):
    data = api_get(f'/officers/{officer_id}/appointments', api_key)
    return data.get('items', []) if data else []


def extract_officer_id(officer):
    try:
        path = officer['links']['officer']['appointments']
        return path.split('/officers/')[1].split('/')[0]
    except (KeyError, IndexError):
        return None


def score_director(appointments):
    score = 0
    flagged = []

    for appt in appointments:
        company_info = appt.get('appointed_to', {})
        status = company_info.get('company_status', '').lower().replace(' ', '-')
        company_name = company_info.get('company_name', 'Unknown Company')

        matched = next((s for s in BAD_STATUSES if s in status), None)
        if matched:
            weight = 2 if any(h in status for h in HEAVY_STATUSES) else 1
            score += weight
            flagged.append({
                'name': company_name,
                'number': company_info.get('company_number', ''),
                'status': matched,
            })

    if score == 0:
        rating = 'LOW'
    elif score <= 1:
        rating = 'MEDIUM'
    else:
        rating = 'HIGH'

    return rating, flagged


def print_director(director_name, rating, total_appointments, flagged):
    colour = {
        'HIGH': Fore.RED,
        'MEDIUM': Fore.YELLOW,
        'LOW': Fore.GREEN,
    }[rating]

    badge = f"[{rating}]"
    print(f"  {colour}{badge:<8}{Style.RESET_ALL} {director_name}")
    print(f"           Total appointments on record: {total_appointments}")

    if flagged:
        for fc in flagged:
            print(f"           {Fore.RED}↳ {fc['name']} ({fc['number']}) — {fc['status']}{Style.RESET_ALL}")
    print()


def export_csv(company_name, rows):
    filename = company_name.replace(' ', '_').replace('/', '_') + '_risk_report.csv'
    with open(filename, 'w', newline='', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=['director', 'rating', 'total_appointments', 'flagged_companies'])
        writer.writeheader()
        writer.writerows(rows)
    return filename


def main():
    init(autoreset=True)

    print(Fore.CYAN + Style.BRIGHT + "\n  Companies House Director Risk Checker")
    print(Fore.CYAN + "  by Phil Atkins — phillipatkins.co.uk\n")

    api_key = get_api_key()

    query = ' '.join(sys.argv[1:]).strip() if len(sys.argv) > 1 else ''
    if not query:
        query = input("Company name to check: ").strip()
    if not query:
        print(Fore.RED + "No company name provided.")
        sys.exit(1)

    print(f"\nSearching for '{query}'...\n")
    results = search_companies(query, api_key)

    if not results:
        print(Fore.RED + "No companies found.")
        sys.exit(1)

    for i, c in enumerate(results):
        status_str = c.get('company_status', 'unknown')
        print(f"  {i + 1}. {c.get('title', 'Unknown')} ({c.get('company_number', '')}) — {status_str}")

    print()
    choice = input("Select [1]: ").strip()
    idx = (int(choice) - 1) if choice.isdigit() and 1 <= int(choice) <= len(results) else 0
    company = results[idx]
    company_name = company.get('title', 'Unknown')
    company_number = company.get('company_number', '')

    print(f"\nFetching directors for {company_name} ({company_number})...\n")
    directors = get_active_directors(company_number, api_key)

    if not directors:
        print("No active directors found.")
        sys.exit(0)

    print(f"Found {len(directors)} active director(s). Checking appointment history...\n")
    print("─" * 60)
    print()

    report_rows = []
    counts = {'HIGH': 0, 'MEDIUM': 0, 'LOW': 0}

    for officer in directors:
        director_name = officer.get('name', 'Unknown')
        officer_id = extract_officer_id(officer)

        if not officer_id:
            continue

        appointments = get_appointments(officer_id, api_key)
        rating, flagged = score_director(appointments)

        print_director(director_name, rating, len(appointments), flagged)
        counts[rating] += 1

        report_rows.append({
            'director': director_name,
            'rating': rating,
            'total_appointments': len(appointments),
            'flagged_companies': ' | '.join(
                f"{f['name']} ({f['status']})" for f in flagged
            ),
        })

    print("─" * 60)
    print(
        f"\n  Summary: "
        f"{Fore.RED}{counts['HIGH']} HIGH{Style.RESET_ALL}  "
        f"{Fore.YELLOW}{counts['MEDIUM']} MEDIUM{Style.RESET_ALL}  "
        f"{Fore.GREEN}{counts['LOW']} LOW{Style.RESET_ALL}"
    )

    filename = export_csv(company_name, report_rows)
    print(f"\n  Report saved → {filename}\n")


if __name__ == '__main__':
    main()
