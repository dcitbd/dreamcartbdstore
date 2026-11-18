/**
 * DREAM CART BD — FRAUD DETECTION & COURIER PERFORMANCE MODAL
 * Live Multi-Source Fraud Intelligence (Website Orders + Connected Couriers + FraudBD + eCourier + Marketplaces)
 */

import { apiClient } from '../api/client.js';
import { toast } from './Toast.js';

export function renderFraudModal() {
  return `
    <div id="fraud-modal-backdrop" class="modal-backdrop hidden" style="display: none !important;">
      <div class="modal-card p-6">

        <div class="flex justify-between items-center mb-4 border-b border-slate-100 dark:border-slate-800 pb-3">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center font-bold text-sm">
              🛡️
            </div>
            <div>
              <h3 class="font-bold text-slate-900 dark:text-white text-base">Multi-Courier Fraud & Delivery Risk Analyzer</h3>
              <p class="text-xs text-slate-500">Live Website Orders + Steadfast, Pathao, RedX, eCourier & FraudBD intelligence</p>
            </div>
          </div>
          <button id="btn-close-fraud-modal" onclick="window.closeFraudModal()" class="text-slate-400 hover:text-slate-600 p-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>

        <!-- Phone Input -->
        <div class="mb-4">
          <label class="form-label text-xs">Customer Phone Number</label>
          <div class="flex gap-2">
            <div class="flex items-center bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 text-xs font-bold text-slate-600 dark:text-slate-300">
              🇧🇩 +88
            </div>
            <input
              type="tel"
              id="fraud-phone-input"
              placeholder="017XXXXXXXX"
              value=""
              class="form-control text-sm py-2 flex-1 dark:bg-slate-800"
            />
            <button id="btn-run-fraud-check" onclick="window.runFraudCheckModal()" class="btn-primary text-xs py-2 px-4 whitespace-nowrap">
              Run Check
            </button>
          </div>
        </div>

        <!-- Result Card Container -->
        <div id="fraud-results-container" class="space-y-4">
          <div class="p-4 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-500 text-center">
            কাস্টমারের ১১ ডিজিটের মোবাইল নাম্বার লিখে <strong>Run Check</strong> বাটনে ক্লিক করুন। ওয়েবসাইট অর্ডার এবং সকল কানেক্টেড কুরিয়ারের লাইভ রিপোর্ট দেখাবে।
          </div>
        </div>

      </div>
    </div>
  `;
}

// Global modal triggers
if (typeof window !== 'undefined') {
  window.openFraudModal = function(phone) {
    const m = document.getElementById('fraud-modal-backdrop');
    if (m) {
      m.classList.remove('hidden');
      m.classList.add('active');
      m.style.setProperty('display', 'flex', 'important');
      if (phone) {
        const inp = document.getElementById('fraud-phone-input');
        if (inp) inp.value = phone;
        window.runFraudCheckModal(false);
      }
    }
  };

  window.closeFraudModal = function() {
    const m = document.getElementById('fraud-modal-backdrop');
    if (m) {
      m.classList.add('hidden');
      m.classList.remove('active');
      m.style.setProperty('display', 'none', 'important');
    }
  };

  window.runFraudCheckModal = async function(forceRefresh = false) {
    const inp = document.getElementById('fraud-phone-input');
    const phone = inp ? inp.value.trim() : '';
    const container = document.getElementById('fraud-results-container');
    const btn = document.getElementById('btn-run-fraud-check');
    if (!phone) {
      if (toast && toast.show) toast.show({ type: 'warning', title: 'মোবাইল নাম্বার দিন', message: 'অনুগ্রহ করে কাস্টমারের মোবাইল নাম্বার লিখুন।' });
      return;
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Checking...';
    }
    if (container) {
      container.innerHTML = `<div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800 border text-xs text-center text-slate-500">⏳ ওয়েবসাইট ও কুরিয়ার সার্ভার থেকে ফ্রড হিস্ট্রি যাচাই হচ্ছে...</div>`;
    }

    try {
      const res = await apiClient.request('fraud/check', 'POST', { phone, force: Boolean(forceRefresh) });
      if (!res || res.success === false) {
        if (container) {
          container.innerHTML = `<div class="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 text-xs text-rose-600 font-bold">${res?.message || 'ফ্রড চেক সম্পন্ন করা যায়নি।'}</div>`;
        }
        return;
      }

      const summary = res.summary || {};
      const risk = res.risk || { level: 'UNKNOWN', label: 'নতুন কাস্টমার (No History)', action: 'Call Confirmation' };
      const rows = Array.isArray(res.rows) ? res.rows : [];
      const rate = summary.success_rate !== null && summary.success_rate !== undefined ? Number(summary.success_rate) : null;
      const isUnknown = risk.level === 'UNKNOWN';
      const isHigh = risk.level === 'HIGH' || risk.level === 'VERY_HIGH';
      const isMed = risk.level === 'MEDIUM';

      const cardBg = isUnknown
        ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/60 text-amber-800 dark:text-amber-200'
        : (isHigh
          ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800/60 text-rose-800 dark:text-rose-200'
          : (isMed
            ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/60 text-amber-800 dark:text-amber-200'
            : 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800/60 text-emerald-800 dark:text-emerald-200'));

      const barColor = isHigh ? 'bg-rose-600' : (isMed || isUnknown ? 'bg-amber-500' : 'bg-emerald-600');
      const barWidth = rate !== null ? Math.max(0, Math.min(100, rate)) : 0;

      if (container) {
        container.innerHTML = `
          <div class="p-4 rounded-xl border ${cardBg}">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-bold uppercase tracking-wider">Overall Success Rate (${res.phone})</span>
              <span class="text-lg font-black font-mono">${rate !== null ? (rate + '%') : 'N/A'}</span>
            </div>
            <div class="w-full bg-slate-200 dark:bg-slate-800 h-2.5 rounded-full overflow-hidden">
              <div class="${barColor} h-full rounded-full transition-all" style="width: ${barWidth}%;"></div>
            </div>
            <div class="mt-2 text-xs font-medium flex flex-wrap items-center justify-between gap-1.5">
              <span>${isHigh ? '🚨' : (isMed || isUnknown ? '⚠️' : '✅')} Status: <strong>${risk.label}</strong>${risk.score !== null && risk.score !== undefined ? ` (Risk Score: ${risk.score}/100)` : ''}</span>
              <span class="font-bold">${risk.action || ''}</span>
            </div>
          </div>

          <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden text-xs max-h-64 overflow-y-auto">
            <table class="w-full text-left">
              <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold">
                <tr>
                  <th class="p-2.5">সোর্স / কুরিয়ার</th>
                  <th class="p-2.5 text-center">সাকসেস</th>
                  <th class="p-2.5 text-center">ক্যানসেল</th>
                  <th class="p-2.5 text-center">মোট</th>
                  <th class="p-2.5 text-right">রেশিও</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                ${rows.map(r => `
                  <tr class="${r.isWebsite ? 'bg-emerald-50/40 dark:bg-emerald-950/20 font-bold' : ''}">
                    <td class="p-2.5 font-bold">
                      ${r.name}
                      ${r.rating ? `<div class="text-[10px] font-normal text-indigo-600">${r.rating}</div>` : ''}
                    </td>
                    <td class="p-2.5 text-center text-emerald-600 font-mono font-semibold">${r.success}</td>
                    <td class="p-2.5 text-center text-rose-500 font-mono font-semibold">${r.cancel}</td>
                    <td class="p-2.5 text-center font-mono font-bold">${r.total}</td>
                    <td class="p-2.5 text-right font-mono font-bold">${r.ratio !== null && r.ratio !== undefined ? (r.ratio + '%') : '—'}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `;
      }
    } catch (err) {
      if (container) {
        container.innerHTML = `<div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-600 font-bold">ফ্রড চেক করতে সমস্যা হয়েছে।</div>`;
      }
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Run Check';
      }
    }
  };
}
