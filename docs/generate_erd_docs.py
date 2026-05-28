from __future__ import annotations

from collections import OrderedDict, defaultdict
from dataclasses import dataclass
from datetime import date
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent
SCHEMA_PATH = ROOT / 'database' / 'schema.sql'
DOCS_DIR = ROOT / 'docs'
ERD_DOMAINS_DIR = DOCS_DIR / 'erd-domains'
TODAY = date.today().isoformat()

BASE_DOMAINS = OrderedDict([
    ('workflow', {
        'title': 'Workflow',
        'code': 'WRK',
        'description': 'Workflow routing, tasking, application progression, and execution logs.',
        'tables': [
            'tb_application_queue', 'tb_appnstatus', 'tb_tasks', 'tb_task_alerts',
            'tb_task_comments', 'tb_task_completion_queue', 'tb_task_delegation_logs', 'tb_workflow_logs'
        ],
    }),
    ('registry', {
        'title': 'Registry',
        'code': 'REG',
        'description': 'Staff due intake, canonical pension registry, documents, file custody, and life-certificate records.',
        'tables': [
            'tb_staffdue', 'tb_staff_documents', 'tb_staff_due_delete_requests', 'tb_fileregistry',
            'tb_file_movements', 'tb_file_registry_delete_requests', 'tb_file_registry_recycle_bin',
            'tb_lifecertificates', 'tb_life_certificate_submissions', 'tb_pensioner_death_reports'
        ],
    }),
    ('claims', {
        'title': 'Claims',
        'code': 'CLM',
        'description': 'Claim submissions and claim-status tracking.',
        'tables': ['tb_appnsubmissions', 'tb_claimstatus'],
    }),
    ('payroll', {
        'title': 'Payroll',
        'code': 'PAY',
        'description': 'Payroll uploads, cycle reconciliation, suspension loads, and monthly registry status.',
        'tables': [
            'tb_payrolls', 'tb_payroll_pension', 'tb_payroll_gratuity', 'tb_payroll_arrears',
            'tb_payroll_suspended', 'tb_payroll_upload_cycles', 'tb_payroll_upload_entries',
            'tb_payroll_audit_logs', 'tb_registry_payroll_monthly_status', 'tb_suspension_upload_cycles',
            'tb_suspension_upload_entries', 'tb_retained_payments'
        ],
    }),
    ('arrears', {
        'title': 'Arrears',
        'code': 'ARR',
        'description': 'Arrears ledgers, payments, allocations, accountability, and gratuity-schedule analysis.',
        'tables': [
            'tb_arrearstracking', 'tb_budgetforecast', 'tb_arrears_ledger', 'tb_arrears_payments',
            'tb_arrears_payment_allocations', 'tb_arrears_accountability_submissions',
            'tb_arrears_accountability_files', 'tb_gratuity_schedule_cycles',
            'tb_gratuity_schedule_entries', 'tb_gratuity_schedule_allocations'
        ],
    }),
    ('messaging', {
        'title': 'Messaging',
        'code': 'MSG',
        'description': 'Messages, attachments, recipients, notifications, and broadcast delivery state.',
        'tables': [
            'tb_messages', 'tb_message_attachments', 'tb_message_recipients', 'tb_message_storage_snapshots',
            'tb_notification_queue', 'tb_notification_digest_runs', 'tb_broadcast_messages', 'tb_user_broadcast_status'
        ],
    }),
    ('feedback', {
        'title': 'Feedback',
        'code': 'FBK',
        'description': 'Feedback submissions and activity history.',
        'tables': ['tb_feedback_submissions', 'tb_feedback_activity'],
    }),
    ('users_access', {
        'title': 'Users & Access',
        'code': 'UAC',
        'description': 'Users, roles, permissions, sessions, and app-level access settings.',
        'tables': [
            'tb_users', 'tb_roles', 'tb_role_permissions', 'tb_user_permissions', 'tb_user_settings',
            'tb_user_sessions', 'tb_session_settings', 'tb_session_metrics', 'tb_app_settings'
        ],
    }),
    ('analytics_ops', {
        'title': 'Analytics & Ops',
        'code': 'OPS',
        'description': 'Analytics digests, exports/imports, scans, backups, logs, and operational telemetry.',
        'tables': [
            'tb_analytics_digest_runs', 'tb_analytics_snapshots', 'tb_audit_logs', 'tb_backup_logs',
            'tb_data_export_runs', 'tb_data_import_runs', 'tb_file_scan_logs', 'tb_system_logs',
            'tb_system_log_resolutions', 'tb_user_logs', 'tb_ip_geolocation'
        ],
    }),
    ('content_reference', {
        'title': 'Content & Reference',
        'code': 'CNT',
        'description': 'Titles, public guidance content, lookup/reference catalogs, and podcast metadata.',
        'tables': [
            'tb_titles', 'tb_faq_entries', 'tb_terms_clauses', 'tb_poldistricts', 'tb_pridistricts',
            'tb_priregions', 'tb_priunits', 'tb_uganda_public_holidays', 'tb_podcast_videos', 'tb_podcast_views'
        ],
    }),
])

DOC_SPECS = OrderedDict([
    ('workflow', {
        'title': 'Workflow ERD',
        'description': BASE_DOMAINS['workflow']['description'],
        'tables': BASE_DOMAINS['workflow']['tables'],
    }),
    ('registry', {
        'title': 'Registry ERD',
        'description': BASE_DOMAINS['registry']['description'],
        'tables': BASE_DOMAINS['registry']['tables'],
    }),
    ('claims', {
        'title': 'Claims ERD',
        'description': BASE_DOMAINS['claims']['description'],
        'tables': BASE_DOMAINS['claims']['tables'],
    }),
    ('payroll', {
        'title': 'Payroll ERD',
        'description': BASE_DOMAINS['payroll']['description'],
        'tables': BASE_DOMAINS['payroll']['tables'] + [
            'tb_gratuity_schedule_cycles', 'tb_gratuity_schedule_entries', 'tb_gratuity_schedule_allocations'
        ],
    }),
    ('arrears', {
        'title': 'Arrears ERD',
        'description': BASE_DOMAINS['arrears']['description'],
        'tables': BASE_DOMAINS['arrears']['tables'],
    }),
    ('claims_arrears', {
        'title': 'Claims & Arrears ERD',
        'description': 'Combined claim intake, arrears accounting, payment allocation, and budgeting view.',
        'tables': BASE_DOMAINS['claims']['tables'] + BASE_DOMAINS['arrears']['tables'] + ['tb_retained_payments'],
    }),
    ('messaging', {
        'title': 'Messaging ERD',
        'description': BASE_DOMAINS['messaging']['description'],
        'tables': BASE_DOMAINS['messaging']['tables'],
    }),
    ('feedback', {
        'title': 'Feedback ERD',
        'description': BASE_DOMAINS['feedback']['description'],
        'tables': BASE_DOMAINS['feedback']['tables'],
    }),
    ('feedback_support', {
        'title': 'Feedback & Support ERD',
        'description': 'Feedback workflow plus public support content and policy guidance tables.',
        'tables': BASE_DOMAINS['feedback']['tables'] + ['tb_faq_entries', 'tb_terms_clauses'],
    }),
    ('users_access', {
        'title': 'Users & Access ERD',
        'description': BASE_DOMAINS['users_access']['description'],
        'tables': BASE_DOMAINS['users_access']['tables'],
    }),
    ('analytics_ops', {
        'title': 'Analytics & Ops ERD',
        'description': BASE_DOMAINS['analytics_ops']['description'],
        'tables': BASE_DOMAINS['analytics_ops']['tables'] + ['tb_notification_digest_runs'],
    }),
    ('content_reference', {
        'title': 'Content & Reference ERD',
        'description': BASE_DOMAINS['content_reference']['description'],
        'tables': BASE_DOMAINS['content_reference']['tables'],
    }),
    ('content_engagement', {
        'title': 'Content & Engagement ERD',
        'description': 'Podcast publishing, views, broadcasts, and notification-facing engagement records.',
        'tables': [
            'tb_podcast_videos', 'tb_podcast_views', 'tb_broadcast_messages',
            'tb_user_broadcast_status', 'tb_notification_queue', 'tb_messages'
        ],
    }),
])

CREATE_TABLE_RE = re.compile(r'(?ms)^CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*?)^\) ENGINE=.*?;\s*')
KEY_CALL_RE = re.compile(r"CALL schema_add_key_if_missing\('([^']+)', '([^']+)', '((?:[^']|'')*)'\);")
FK_CALL_RE = re.compile(r"CALL schema_add_fk_if_missing\('([^']+)', '([^']+)', '((?:[^']|'')*)'\);")

@dataclass(frozen=True)
class Column:
    name: str
    definition: str
    type_label: str

@dataclass(frozen=True)
class Relationship:
    source_table: str
    source_columns: tuple[str, ...]
    target_table: str
    target_columns: tuple[str, ...]
    constraint_name: str


def parse_schema_tables(schema_text: str) -> OrderedDict[str, list[Column]]:
    tables: OrderedDict[str, list[Column]] = OrderedDict()
    for match in CREATE_TABLE_RE.finditer(schema_text):
        table_name = match.group(1)
        body = match.group(2)
        columns: list[Column] = []
        for raw_line in body.splitlines():
            line = raw_line.strip().rstrip(',')
            if not line.startswith('`'):
                continue
            col_match = re.match(r'`([^`]+)`\s+(.+)$', line)
            if not col_match:
                continue
            name = col_match.group(1)
            definition = line
            columns.append(Column(name=name, definition=definition, type_label=mermaid_type_for_column(definition)))
        tables[table_name] = columns
    return tables


def mermaid_type_for_column(definition: str) -> str:
    match = re.match(r'`[^`]+`\s+([a-zA-Z]+)', definition)
    raw = (match.group(1).lower() if match else 'string')
    if raw in {'varchar', 'char', 'enum', 'set'}:
        return 'string'
    if raw in {'tinyint'}:
        return 'bool' if '(1)' in definition else 'int'
    if raw in {'smallint', 'mediumint', 'int', 'integer'}:
        return 'int'
    if raw == 'bigint':
        return 'bigint'
    if raw in {'decimal', 'numeric', 'double', 'float'}:
        return 'decimal'
    if raw in {'text', 'mediumtext', 'longtext'}:
        return 'text'
    if raw in {'datetime', 'timestamp', 'date', 'time'}:
        return raw
    if raw == 'year':
        return 'int'
    if raw in {'json'}:
        return 'json'
    if raw in {'blob', 'longblob', 'mediumblob'}:
        return 'blob'
    return 'string'


def parse_column_list(clause: str) -> tuple[str, ...]:
    columns: list[str] = []
    for raw_part in clause.split(','):
        cleaned = raw_part.strip().strip('`').strip()
        if cleaned:
            columns.append(cleaned)
    return tuple(columns)


def mermaid_identifier(value: str) -> str:
    cleaned = re.sub(r'[^A-Za-z0-9_]', '_', value.strip())
    if not cleaned:
        return 'unnamed'
    if not re.match(r'[A-Za-z_]', cleaned):
        cleaned = '_' + cleaned
    return cleaned


def mermaid_label(value: str) -> str:
    return re.sub(r'[^A-Za-z0-9_ ,.-]', '_', value.strip())


def parse_keys(schema_text: str) -> tuple[dict[str, set[str]], dict[str, set[str]]]:
    pk_map: dict[str, set[str]] = defaultdict(set)
    uk_map: dict[str, set[str]] = defaultdict(set)
    for table, key_name, clause in KEY_CALL_RE.findall(schema_text):
        clause = clause.replace("''", "'")
        if 'PRIMARY KEY' in clause:
            match = re.search(r'PRIMARY KEY\s*\(([^)]+)\)', clause, re.I)
            if match:
                pk_map[table].update(parse_column_list(match.group(1)))
        elif 'UNIQUE KEY' in clause:
            match = re.search(r'UNIQUE KEY\s+`?[^`\s(]+`?\s*\(([^)]+)\)', clause, re.I)
            if match:
                uk_map[table].update(parse_column_list(match.group(1)))
    return pk_map, uk_map


def parse_relationships(schema_text: str) -> list[Relationship]:
    relationships: list[Relationship] = []
    for table, constraint_name, clause in FK_CALL_RE.findall(schema_text):
        clause = clause.replace("''", "'")
        match = re.search(
            r'FOREIGN KEY\s*\(([^\)]+)\)\s*REFERENCES\s*`?([^`\s\(]+)`?\s*\(([^\)]+)\)',
            clause,
            re.I,
        )
        if not match:
            continue
        source_columns = parse_column_list(match.group(1))
        target_table = match.group(2)
        target_columns = parse_column_list(match.group(3))
        relationships.append(Relationship(
            source_table=table,
            source_columns=source_columns,
            target_table=target_table,
            target_columns=target_columns,
            constraint_name=constraint_name,
        ))
    return relationships


def unique_preserve(items: list[str]) -> list[str]:
    seen: set[str] = set()
    ordered: list[str] = []
    for item in items:
        if item in seen:
            continue
        seen.add(item)
        ordered.append(item)
    return ordered


def short_name(table_name: str) -> str:
    return table_name[3:] if table_name.startswith('tb_') else table_name


def entity_lines(table_name: str, columns: list[Column], pk_map: dict[str, set[str]], fk_columns: dict[str, set[str]]) -> list[str]:
    entity_name = mermaid_identifier(table_name)
    lines = [f'  {entity_name} {{']
    for column in columns:
        flags: list[str] = []
        if column.name in pk_map.get(table_name, set()):
            flags.append('PK')
        if column.name in fk_columns.get(table_name, set()):
            flags.append('FK')
        suffix = (' ' + ', '.join(flags)) if flags else ''
        lines.append(f'    {column.type_label} {mermaid_identifier(column.name)}{suffix}')
    lines.append('  }')
    return lines


def relationship_lines(table_order: OrderedDict[str, list[Column]], relationships: list[Relationship], included_tables: set[str]) -> list[str]:
    ordered = {name: idx for idx, name in enumerate(table_order.keys())}
    filtered = [r for r in relationships if r.source_table in included_tables and r.target_table in included_tables]
    filtered.sort(key=lambda rel: (ordered.get(rel.target_table, 9999), ordered.get(rel.source_table, 9999), rel.constraint_name))
    lines: list[str] = []
    for rel in filtered:
        label = mermaid_label(', '.join(rel.source_columns))
        lines.append(f'  {mermaid_identifier(rel.target_table)} ||--o{{ {mermaid_identifier(rel.source_table)} : {label}')
    return lines


def build_erd_mermaid(table_order: OrderedDict[str, list[Column]], pk_map: dict[str, set[str]], fk_columns: dict[str, set[str]], relationships: list[Relationship], tables: list[str]) -> str:
    included_tables = set(tables)
    lines = ['erDiagram']
    for table_name, columns in table_order.items():
        if table_name not in included_tables:
            continue
        lines.extend(entity_lines(table_name, columns, pk_map, fk_columns))
    lines.extend(relationship_lines(table_order, relationships, included_tables))
    return '\n'.join(lines) + '\n'


def write_text(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding='utf-8')


def markdown_doc(title: str, description: str, mermaid_text: str, tables: list[str], relation_count: int) -> str:
    table_lines = '\n'.join(f'- `{table}`' for table in tables)
    return (
        f'# {title}\n\n'
        f'Generated from `database/schema.sql` on {TODAY}.\n\n'
        f'{description}\n\n'
        f'- Tables: {len(tables)}\n'
        f'- Relationships shown: {relation_count}\n\n'
        f'## Tables Covered\n\n'
        f'{table_lines}\n\n'
        f'## Mermaid ERD\n\n'
        f'```mermaid\n{mermaid_text}```\n'
    )


def compute_relation_count(relationships: list[Relationship], included_tables: set[str]) -> int:
    return sum(1 for rel in relationships if rel.source_table in included_tables and rel.target_table in included_tables)


def connector_summary(source_tables: list[str], max_items: int = 3) -> str:
    ordered = unique_preserve([short_name(name) for name in source_tables])
    if not ordered:
        return 'No direct FK connectors'
    if len(ordered) <= max_items:
        return '<br/>'.join(ordered)
    return '<br/>'.join(ordered[:max_items] + [f'+{len(ordered) - max_items} more'])


def node_label(domain_title: str, tables: list[str], max_items: int = 4) -> str:
    items = [short_name(name) for name in tables]
    if len(items) > max_items:
        shown = items[:max_items] + [f'+{len(items) - max_items} more']
    else:
        shown = items
    return f"{domain_title}<br/>{len(tables)} tables<br/>" + '<br/>'.join(shown)


def connector_cell(source_tables: list[str], max_items: int = 2) -> str:
    ordered = unique_preserve([short_name(name) for name in source_tables])
    if not ordered:
        return '.'
    if len(ordered) <= max_items:
        return ', '.join(f'`{name}`' for name in ordered)
    return ', '.join(f'`{name}`' for name in ordered[:max_items]) + f', `+{len(ordered) - max_items}`'


def build_interdomain(relations: list[Relationship], domain_map: dict[str, list[str]]) -> tuple[str, str, str]:
    table_to_domain: dict[str, str] = {}
    for domain_key, tables in domain_map.items():
        for table in tables:
            table_to_domain[table] = domain_key

    pair_fk_sources: dict[tuple[str, str], list[str]] = defaultdict(list)
    pair_fk_details: dict[tuple[str, str], list[str]] = defaultdict(list)
    for rel in relations:
        source_domain = table_to_domain.get(rel.source_table)
        target_domain = table_to_domain.get(rel.target_table)
        if not source_domain or not target_domain or source_domain == target_domain:
            continue
        pair = tuple(sorted((source_domain, target_domain), key=lambda key: list(domain_map.keys()).index(key)))
        pair_fk_sources[pair].append(rel.source_table)
        pair_fk_details[pair].append(
            f"`{rel.source_table}.{', '.join(rel.source_columns)}` -> `{rel.target_table}.{', '.join(rel.target_columns)}`"
        )

    flow_lines = [
        'flowchart TB',
        '  classDef domain fill:#f8fafc,stroke:#334155,stroke-width:1.5px,color:#0f172a;',
        '  classDef connector fill:#fff7ed,stroke:#c2410c,stroke-width:2px,color:#7c2d12;',
    ]
    for domain_key, spec in BASE_DOMAINS.items():
        label = node_label(spec['title'], spec['tables'])
        flow_lines.append(f"  {spec['code']}[\"{label}\"]")
    for pair, source_tables in sorted(pair_fk_sources.items(), key=lambda item: (list(BASE_DOMAINS.keys()).index(item[0][0]), list(BASE_DOMAINS.keys()).index(item[0][1]))):
        left = BASE_DOMAINS[pair[0]]['code']
        right = BASE_DOMAINS[pair[1]]['code']
        label = f"{len(source_tables)} FK path{'s' if len(source_tables) != 1 else ''}<br/>{connector_summary(source_tables)}"
        flow_lines.append(f"  {left} ---|\"{label}\"| {right}")
    flow_lines.append('  class WRK,REG,CLM,PAY,ARR,MSG,FBK,UAC,OPS,CNT domain;')
    flow_mmd = '\n'.join(flow_lines) + '\n'

    md_lines = [
        '# Inter-Domain ERD',
        '',
        'Generated from `database/schema.sql`. This map shows how the grouped domains connect through foreign-key paths and shared bridge tables.',
        '',
        '```mermaid',
        flow_mmd.rstrip(),
        '```',
        '',
        '## Domain Coverage',
        '',
        '| Code | Domain | Tables |',
        '| --- | --- | ---: |',
    ]
    for domain_key, spec in BASE_DOMAINS.items():
        md_lines.append(f"| `{spec['code']}` | {spec['title']} | {len(spec['tables'])} |")
    md_lines.extend([
        '',
        '## Connector Details',
        '',
        '| Domain Pair | Connector Tables | Foreign-Key Paths |',
        '| --- | --- | --- |',
    ])
    for pair, source_tables in sorted(pair_fk_sources.items(), key=lambda item: (list(BASE_DOMAINS.keys()).index(item[0][0]), list(BASE_DOMAINS.keys()).index(item[0][1]))):
        left = BASE_DOMAINS[pair[0]]['title']
        right = BASE_DOMAINS[pair[1]]['title']
        connectors = ', '.join(f'`{name}`' for name in unique_preserve(source_tables))
        details = '<br/>'.join(unique_preserve(pair_fk_details[pair]))
        md_lines.append(f'| {left} <-> {right} | {connectors} | {details} |')
    interdomain_md = '\n'.join(md_lines) + '\n'

    matrix_lines = [
        '# Inter-Domain Connector Matrix',
        '',
        'Generated from `database/schema.sql`. Each cell lists the main source tables whose foreign keys connect one domain to another.',
        '',
        '## Domain Codes',
        '',
        '| Code | Domain |',
        '| --- | --- |',
    ]
    for domain_key, spec in BASE_DOMAINS.items():
        matrix_lines.append(f"| `{spec['code']}` | {spec['title']} |")
    matrix_lines.extend(['', '## Matrix', ''])
    domain_keys = list(BASE_DOMAINS.keys())
    header = '| From \\ To | ' + ' | '.join(f"`{BASE_DOMAINS[key]['code']}`" for key in domain_keys) + ' |'
    separator = '| --- | ' + ' | '.join('---' for _ in domain_keys) + ' |'
    matrix_lines.extend([header, separator])
    for row_key in domain_keys:
        row_cells = []
        for col_key in domain_keys:
            if row_key == col_key:
                row_cells.append('--')
                continue
            pair = tuple(sorted((row_key, col_key), key=lambda key: domain_keys.index(key)))
            row_cells.append(connector_cell(pair_fk_sources.get(pair, [])))
        matrix_lines.append(f"| `{BASE_DOMAINS[row_key]['code']}` | " + ' | '.join(row_cells) + ' |')
    matrix_lines.extend(['', '## Detailed Connector Paths', '', '| Domain Pair | Foreign-Key Paths |', '| --- | --- |'])
    for pair, details in sorted(pair_fk_details.items(), key=lambda item: (domain_keys.index(item[0][0]), domain_keys.index(item[0][1]))):
        matrix_lines.append(
            f"| {BASE_DOMAINS[pair[0]]['title']} <-> {BASE_DOMAINS[pair[1]]['title']} | {'<br/>'.join(unique_preserve(details))} |"
        )
    matrix_md = '\n'.join(matrix_lines) + '\n'

    return flow_mmd, interdomain_md, matrix_md


def main() -> None:
    schema_text = SCHEMA_PATH.read_text(encoding='utf-8', errors='ignore')
    table_order = parse_schema_tables(schema_text)
    pk_map, _uk_map = parse_keys(schema_text)
    relationships = parse_relationships(schema_text)
    fk_columns: dict[str, set[str]] = defaultdict(set)
    for rel in relationships:
        fk_columns[rel.source_table].update(rel.source_columns)

    all_tables = list(table_order.keys())
    full_mermaid = build_erd_mermaid(table_order, pk_map, fk_columns, relationships, all_tables)
    write_text(DOCS_DIR / 'erd.mmd', full_mermaid)
    full_md = markdown_doc(
        title='PensionApp Full ERD',
        description='Complete schema view covering every table currently defined in `database/schema.sql`. Mermaid types are normalized for readability while column names and relationship paths remain schema-accurate.',
        mermaid_text=full_mermaid,
        tables=all_tables,
        relation_count=len(relationships),
    )
    write_text(DOCS_DIR / 'erd.md', full_md)

    covered_tables: set[str] = set()
    index_lines = [
        '# Domain ERDs',
        '',
        f'Generated from `database/schema.sql` on {TODAY}.',
        '',
        '| Domain | Files | Tables | Relationships |',
        '| --- | --- | ---: | ---: |',
    ]

    for stem, spec in DOC_SPECS.items():
        tables = [table for table in spec['tables'] if table in table_order]
        tables = list(OrderedDict.fromkeys(tables))
        covered_tables.update(tables)
        mermaid = build_erd_mermaid(table_order, pk_map, fk_columns, relationships, tables)
        relation_count = compute_relation_count(relationships, set(tables))
        write_text(ERD_DOMAINS_DIR / f'{stem}.mmd', mermaid)
        write_text(
            ERD_DOMAINS_DIR / f'{stem}.md',
            markdown_doc(spec['title'], spec['description'], mermaid, tables, relation_count),
        )
        index_lines.append(
            f"| {spec['title'].replace(' ERD', '')} | `docs/erd-domains/{stem}.md`<br/>`docs/erd-domains/{stem}.mmd` | {len(tables)} | {relation_count} |"
        )

    interdomain_mmd, interdomain_md, matrix_md = build_interdomain(relationships, {k: v['tables'] for k, v in BASE_DOMAINS.items()})
    write_text(ERD_DOMAINS_DIR / 'interdomain_links.mmd', interdomain_mmd)
    write_text(ERD_DOMAINS_DIR / 'interdomain_links.md', interdomain_md)
    write_text(ERD_DOMAINS_DIR / 'interdomain_matrix.md', matrix_md)

    index_lines.extend([
        '',
        '## Inter-Domain Views',
        '',
        '- `docs/erd-domains/interdomain_links.md`',
        '- `docs/erd-domains/interdomain_links.mmd`',
        '- `docs/erd-domains/interdomain_matrix.md`',
        '',
        '- Full ERD: `docs/erd.md` and `docs/erd.mmd`',
    ])
    write_text(DOCS_DIR / 'erd-domains.md', '\n'.join(index_lines) + '\n')

    missing = [table for table in all_tables if table not in covered_tables]
    missing_text = 'none\n' if not missing else '\n'.join(missing) + '\n'
    write_text(ERD_DOMAINS_DIR / '_missing_tables.txt', missing_text)

    print(f'Generated full ERD plus {len(DOC_SPECS)} domain ERDs.')
    print(f'Tables in schema: {len(all_tables)}')
    print(f'Relationships in schema: {len(relationships)}')
    print(f'Missing domain coverage: {len(missing)}')


if __name__ == '__main__':
    main()
