=== WPML Auto-Duplication Mission Critical ===
Contributors: onwardSEO  
Donate link: https://onwardseo.com  
Tags: wpml, multilingual, duplication, automation, make.com, REST API  
Requires at least: 5.8  
Tested up to: 6.5  
Requires PHP: 7.4  
Stable tag: 3.7.1  
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely and automatically duplicates new posts (pushed in via Make.com/Integromat or any REST-API workflow) from a source language into all other WPML languages—content, media and SEO meta intact—so every locale’s blog stays in sync with zero manual effort.

== Description ==

**Typical problem**

*Your automation publishes a post in English (en-GB) but the Spanish, German, French etc. versions never appear, so visitors on those languages see an empty blog.*

**What this plugin does**

1. Detects every fresh post created through the REST API (e.g. by Make.com).  
2. Confirms it is the **original** item in its translation set (not already a duplicate).  
3. Within a safe time-window schedules a WP-Cron task that:  
   * Duplicates the post into each target language configured in WPML.  
   * Copies title, content, featured image, custom fields (including common SEO plugins’ meta).  
4. Logs every step to a private, non-web-accessible directory for auditability.  
5. Enforces quota limits, failure retries and an emergency stop switch—so it’s production-safe.

Built and maintained by **onwardSEO**, the agency behind your multilingual content workflow.

== Installation ==

1. Download or clone the plugin and make sure the folder is named `wpml-mission-critical`.  
2. Upload the folder to **`wp-content/plugins/`** *or* use **Plugins → Add New → Upload** in the WP admin.  
3. Activate the plugin.  
4. Edit `wpml-mission-critical.php` once and switch  


## Technical Appraisal – *WPML Auto-Duplication Mission Critical v3.7.1*

### 🔍 Overview  
Safely duplicates Make/Integromat-origin posts to multiple WPML languages, with quota guards, logging, health checks, CLI support and hardened file-system security.

| Aspect | Highlights |
| ------ | ---------- |
| **Functional scope** | • Auto-detects Make↔️REST posts<br>• Schedules duplicates across target languages<br>• Daily-quota guard, circuit-breaker & auto-retry<br>• Private logfile outside web-root (uploads fallback with Nginx/Apache/IIS rules)<br>• Hourly health-check & log rotation<br>• Admin banner dashboard + AJAX log viewer & Abort/Reset<br>• WP-CLI command bypassing quotas |
| **Code volume** | ≈ **1 700** lines (incl. comments & HTML notices) |
| **Key qualities** | • Namespaced, `strict_types`<br>• Uses nonces/cap checks<br>• Atomic SQL increment for quotas<br>• Fallback paths & defensive error handling |
| **Notable weaknesses** | • `@` error suppression<br>• Logic + HTML mixed in closures<br>• No automated tests / CI<br>• Some duplicated guard logic |

---

### 1. Complexity Score  
**7 / 10** – Multiple intertwined subsystems (duplication engine, cron health, FS security, CLI & admin UX) yet still one-developer manageable.

### 2. Code-Quality Score  
**6.5 / 10**

*Strengths*  
- Clear separation of concerns; modern PHP features  
- Solid security awareness (nonces, directory isolation)

*Areas to improve*  
- Unit tests / CI missing  
- Error-suppression masks issues  
- Mixed presentation logic; not PSR-12 formatted

---

### 3. Effort Estimate (solo above-average developer)

| Phase | Hrs |
| ----- | --- |
| Requirements & design | 8-12 |
| Duplication engine & quotas | 18-22 |
| FS security + logging | 10-14 |
| Admin UI & AJAX | 8-10 |
| Health-check & rotations | 6-8 |
| CLI & docs | 4-6 |
| QA / hardening | 10-14 |
| **Total** | **≈ 64 – 86 hrs** |

---

### 4. Cost Projection (coding only, excl. maintenance)

| Rate type | $/hr | Estimated total |
| --------- | ---- | --------------- |
| Freelance / boutique (US) | **$75** | **$4.8 k – $6.5 k** |
| Mid-market agency (US) | $110 | $7.0 k – $9.5 k |
| Senior offshore | $45 | $2.9 k – $3.9 k |

> Figures assume no scope creep and include basic documentation & hand-off.
