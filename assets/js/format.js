// FINATUM PropertyBank — numbers and dates, in the house style of the Pricer
// and MarketData: 1,234,567 with a true minus sign; dates as 05 Oct 2026.

const nf = {};
function numberFormat(dp) {
  const k = String(dp);
  if (!nf[k]) nf[k] = new Intl.NumberFormat("en-GB", { minimumFractionDigits: dp, maximumFractionDigits: dp });
  return nf[k];
}

/** A number with fixed decimals and thousands separators; "—" for nothing. */
export function fmtNum(v, dp = 0) {
  if (v === null || v === undefined || v === "" || !Number.isFinite(Number(v))) return "—";
  const n = Number(v);
  return (n < 0 ? "−" : "") + numberFormat(dp).format(Math.abs(n));
}

/** Metres: "620 m", "1,250 m". */
export function fmtM(v) {
  return v === null || v === undefined ? "—" : fmtNum(v, 0) + " m";
}

const SYMBOL = { USD: "$", EUR: "€", UAH: "₴" };
/** A price: "$117,000", "€108,500", "₴5,269,713". */
export function fmtMoney(v, cur) {
  if (v === null || v === undefined) return "—";
  return (SYMBOL[cur] || (cur ? cur + " " : "")) + fmtNum(Math.round(v), 0);
}

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/** 05 Oct 2026, from "2026-10-05" or "2026-10-05 12:00:00". */
export function fmtDate(v) {
  if (!v) return "—";
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v));
  return m ? m[3] + " " + MONTHS[+m[2] - 1] + " " + m[1] : String(v);
}

export function fmtDateTime(sql) {
  if (!sql) return "—";
  return fmtDate(sql) + " " + String(sql).slice(11, 16);
}

/** "2 hours ago", from two "YYYY-MM-DD HH:MM:SS" times of the server's clock. */
export function fmtAgo(sql, nowSql) {
  if (!sql) return "";
  const t = Date.parse(String(sql).replace(" ", "T"));
  const now = nowSql ? Date.parse(String(nowSql).replace(" ", "T")) : Date.now();
  const s = Math.max(0, (now - t) / 1000);
  if (s < 90) return "just now";
  if (s < 5400) return Math.round(s / 60) + " minutes ago";
  if (s < 129600) return Math.round(s / 3600) + " hours ago";
  return Math.round(s / 86400) + " days ago";
}

/** Hours between two server times. */
export function hoursBetween(sql, nowSql) {
  if (!sql) return Infinity;
  return (Date.parse(String(nowSql).replace(" ", "T")) - Date.parse(String(sql).replace(" ", "T"))) / 3600000;
}

export function plural(n, word, many) {
  return fmtNum(n, 0) + " " + (n === 1 ? word : many || word + "s");
}
