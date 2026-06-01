/**
 * Bricks Builder Security Audit Tools — Open Source Edition.
 *
 * Read-only security posture report over the bricks-api-bridge. Mirrors the
 * SEO-audit renderer (worst-first, ✗/⚠/✓/ℹ). Detection is plugin-side; this
 * layer only renders. Requires administrator credentials on the bridge.
 */
import { wpGet } from '../utils/wp-api.js';

const ICON = { fail: '✗', warn: '⚠', pass: '✓', info: 'ℹ' };
const SEV = { critical: 'CRITICAL', high: 'HIGH', medium: 'MEDIUM', low: 'LOW', info: 'INFO' };

const securityTools = [
  {
    name: 'bricks_security_audit',
    description: 'Read-only security posture audit of the WordPress + Bricks site behind the bridge. Scores 0-100 (A-F) across Bricks-core CVEs, bridge route permissions (self-audit), code-element exposure, platform currency, config hygiene, and access/transport. Returns findings worst-first with remediation. Any open CRITICAL hard-caps the grade to F. Not a malware scanner. Requires admin credentials.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const data = await wpGet('/security/audit');
        const c = data.summary_counts || {};
        const cap = data.grade_capped ? ' (capped by open CRITICAL)' : '';
        const lines = [
          `Security Audit — score ${data.overall_score}/100 · grade ${data.grade}${cap}`,
          `${c.critical || 0} critical · ${c.high || 0} high · ${c.medium || 0} medium · ${c.low || 0} low · ${c.info || 0} info · ${c.passed || 0} passed`,
          '',
        ];

        const findings = data.findings_worst_first || [];
        if (findings.length === 0) {
          lines.push('No findings — all scored checks passed.');
        } else {
          lines.push('--- Findings (worst first) ---');
          findings.forEach(f => {
            const icon = ICON[f.status] || '•';
            const sev = SEV[f.severity] || f.severity?.toUpperCase() || '';
            lines.push(`${icon} [${sev}] ${f.check} — ${f.detail}`);
            if (f.remediation) lines.push(`    → ${f.remediation}`);
            if (f.ref_url) lines.push(`    ref: ${f.ref_url}`);
          });
        }

        // Category score summary.
        lines.push('');
        lines.push('--- Categories ---');
        (data.categories || []).forEach(cat => {
          const pct = cat.max > 0 ? Math.round((cat.score / cat.max) * 100) : 100;
          lines.push(`  ${cat.category}: ${cat.score}/${cat.max} (${pct}%)`);
        });

        if (data.disclaimers && data.disclaimers.length) {
          lines.push('');
          data.disclaimers.forEach(d => lines.push(`ℹ ${d}`));
        }

        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error running security audit: ${error.message}\n(The /security/audit route requires administrator credentials and the bab_security_audit_enabled flag.)` }] };
      }
    },
  },

  {
    name: 'bricks_security_inventory',
    description: 'Exact software inventory of the site behind the bridge — WordPress core, PHP, Bricks, active theme, all plugins/themes with versions and any known-available updates (read from WordPress own update transients, no external network call). Use to feed version-based checks or to see what is outdated. Requires admin credentials.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const d = await wpGet('/security/inventory/components');
        const lines = [
          `Component Inventory — ${d.site_url || ''}`.trim(),
          `WordPress core: ${d.core?.version || '?'}${d.core?.update_available ? ` → ${d.core.update_available} available` : ' (current)'}`,
          `PHP: ${d.php?.version || '?'}`,
          `Bricks: ${d.bricks?.version || 'not detected'}`,
          `Active theme: ${d.theme_active || '?'}`,
          '',
          `--- Plugins (${(d.plugins || []).length}) ---`,
        ];
        (d.plugins || []).forEach(p => {
          const state = p.active ? 'active' : 'inactive';
          const upd = p.update_available ? ` → ${p.update_available}` : '';
          lines.push(`  ${p.active ? '●' : '○'} ${p.slug} ${p.version}${upd} [${state}]`);
        });
        lines.push('');
        lines.push(`--- Themes (${(d.themes || []).length}) ---`);
        (d.themes || []).forEach(t => {
          const upd = t.update_available ? ` → ${t.update_available}` : '';
          lines.push(`  ${t.slug} ${t.version}${upd}${t.is_child ? ' (child)' : ''}`);
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error fetching inventory: ${error.message}\n(Requires administrator credentials.)` }] };
      }
    },
  },
];

export { securityTools };
