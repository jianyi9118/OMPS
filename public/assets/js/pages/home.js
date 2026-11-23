/* 工作台 by role (design §17): unit head / super admin, department head. Members land on 我的任务. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  if (!view || view.getAttribute("data-page") !== "home") return;

  function link(href, html) { return '<a href="' + O.esc(O.url(href)) + '" style="color:inherit;text-decoration:none">' + html + "</a>"; }
  function pct(v) { return v === null || v === undefined ? "—" : Math.round(v) + "%"; }

  /* completion bar with a line at the time progress */
  function bar(p) {
    return '<span class="wb-prog"><span class="wb-bar' + (p.lagging ? " lag" : "") + '" title="' + O.esc(t("wb.bar_tip", { done: pct(p.progress), time: pct(p.time_pct) })) + '">' +
      '<b style="width:' + Math.min(100, p.progress) + '%"></b><i style="left:' + Math.min(100, p.time_pct) + '%"></i></span><span class="num">' + pct(p.progress) + "</span></span>";
  }

  function statePill(p) {
    if (p.state === "in_progress") return p.lagging ? O.pill(t("wb.lagging"), "bad") : O.pill(t("wb.on_track"), "ok");
    if (p.state === "ended") return p.lagging ? O.pill(t("wb.lagging"), "bad") : O.pill(t("plan.state.ended"), "warn");
    return O.pill(t("plan.state." + p.state), p.state === "done" ? "ok" : "");
  }

  function plansCard(title, plans, wide) {
    var card = document.createElement("div");
    card.className = "card";
    card.innerHTML = '<div class="card-h">' + O.esc(title) + '<span class="r muted" style="font-size:12px">' + O.esc(t("wb.bar_legend")) + "</span></div>";
    card.appendChild(O.table({
      cols: [
        { t: t("wb.col.plan"), cls: "wrap", f: function (p) {
          return O.pill(t("plan.level." + p.level), "info") + " " + link("/plans/tree?plan=" + p.id, "<b>" + O.esc(p.title) + "</b>") +
            '<div class="muted" style="font-size:12px">' + O.esc((wide ? (p.dept || t("plan.unit")) + " · " : "") + p.time) + "</div>";
        } },
        { t: t("wb.col.progress"), f: bar },
        { t: t("wb.col.state"), f: statePill }
      ],
      rows: plans, empty: t("wb.no_plans")
    }));
    return card;
  }

  function deptsCard(rows) {
    var card = document.createElement("div");
    card.className = "card";
    card.innerHTML = '<div class="card-h">' + O.esc(t("wb.depts")) + "</div>";
    card.appendChild(O.table({
      cols: [
        { t: t("stat.col.dept"), f: function (d) { return '<span style="padding-left:' + d.depth * 16 + 'px">' + O.esc(d.name) + "</span>"; } },
        { t: t("stat.col.leader"), f: function (d) { return O.esc(d.leader || "—"); } },
        { t: t("stat.col.people"), cls: "num", f: function (d) { return String(d.people); } },
        { t: t("wb.col.avg"), cls: "num", f: function (d) { return d.plans ? pct(d.progress) : '<span class="muted">' + O.esc(t("wb.no_plans_short")) + "</span>"; } },
        { t: t("wb.col.lagging"), cls: "num", f: function (d) { return d.lagging ? '<b style="color:var(--bad)">' + d.lagging + "</b>" : "0"; } },
        { t: t("wb.col.written"), cls: "num", f: function (d) { return d.people ? d.written + " / " + d.people : "—"; } },
        { t: t("wb.col.pending"), cls: "num", f: function (d) { return d.pending ? link("/performance/review", "<b>" + d.pending + "</b>") : "0"; } }
      ],
      rows: rows, empty: t("common.empty")
    }));
    return card;
  }

  function membersCard(rows) {
    var card = document.createElement("div");
    card.className = "card";
    card.innerHTML = '<div class="card-h">' + O.esc(t("wb.members")) + "</div>";
    var res = { written: ["wb.res.written", "ok"], draft: ["wb.res.draft", "warn"], none: ["wb.res.none", ""] };
    card.appendChild(O.table({
      cols: [
        { t: t("wb.col.name"), f: function (m) { return "<b>" + O.esc(m.name) + "</b>"; } },
        { t: t("wb.col.today"), cls: "wrap", f: function (m) { return m.today.length ? O.esc(m.today.join(t("common.list_sep"))) : '<span class="muted">' + O.esc(t("wb.no_today")) + "</span>"; } },
        { t: t("wb.col.result"), f: function (m) { return O.pill(t(res[m.result][0]), res[m.result][1]); } },
        { t: t("wb.col.week"), cls: "num", f: function (m) { return pct(m.week); } },
        { t: t("wb.col.pending"), cls: "num", f: function (m) { return m.pending ? link("/performance/review", "<b>" + m.pending + "</b>") : "0"; } }
      ],
      rows: rows, empty: t("wb.no_members")
    }));
    return card;
  }

  function sideCard(title, more, body) {
    return '<div class="card"><div class="card-h">' + O.esc(title) + (more ? '<span class="r">' + more + "</span>" : "") + '</div><div class="card-b wb-list">' + body + "</div></div>";
  }

  function side(d) {
    var html = "";
    html += sideCard(t("wb.todo"), d.todo_total ? link("/affairs/todo", O.esc(t("wb.all_n", { n: d.todo_total }))) : "",
      d.todo.length ? d.todo.map(function (r) { return '<a href="' + O.esc(O.url(r.link)) + '">' + O.esc(t("todo.msg." + r.kind, r.params)) + "</a>"; }).join("") : '<span class="muted">' + O.esc(t("wb.no_todo")) + "</span>");
    html += sideCard(t("wb.notices"), link("/affairs/notifications", O.esc(t("wb.all"))),
      d.notices.length ? d.notices.map(function (n) {
        return '<a href="' + O.esc(O.url(n.link || "/affairs/notifications")) + '"' + (n.read ? ' class="muted"' : "") + ">" + O.esc(O.notifyText(n)) + ' <span class="muted" style="font-size:11.5px">' + O.esc(O.ago(n.at)) + "</span></a>";
      }).join("") : '<span class="muted">' + O.esc(t("wb.no_notices")) + "</span>");
    if (d.devices) {
      html += sideCard(t("wb.devices"), "", '<div class="wb-mini">' +
        link("/device/archive", '<span class="k">' + O.esc(t("home.devices")) + '</span><b>' + d.devices.total + "</b>") +
        link("/device/repair", '<span class="k">' + O.esc(t("home.device_faults")) + '</span><b' + (d.devices.faults ? ' style="color:var(--bad)"' : "") + ">" + d.devices.faults + "</b>") +
        link("/device/monitor?tab=anomaly", '<span class="k">' + O.esc(t("home.device_anomalies")) + '</span><b' + (d.devices.anomalies ? ' style="color:var(--warn)"' : "") + ">" + d.devices.anomalies + "</b>") + "</div>");
    }
    if (d.system) html += systemCard(d.system);
    return html;
  }

  function systemCard(s) {
    var rows = [];
    var b = s.backup;
    rows.push([t("wb.sys.backup"), link("/system/backup", !b ? '<span style="color:var(--bad)">' + O.esc(t("home.no_backup")) + "</span>" :
      (b.status === "failed" ? O.pill(t("backup.failed"), "bad") + " " : "") + '<span' + (b.stale ? ' style="color:var(--bad)"' : "") + ">" + O.esc(b.last_ok ? b.last_ok.slice(5, 16) : t("home.no_backup")) + "</span>")]);
    rows.push([t("wb.sys.online"), O.esc(t("wb.sys.online_n", { n: s.online }))]);
    rows.push([t("wb.sys.task"), s.task.ok ? O.pill(t("wb.sys.task_ok"), "ok") + ' <span class="muted" style="font-size:11.5px">' + O.esc(s.task.cli.slice(5, 16)) + "</span>"
      : O.pill(t("wb.sys.task_off"), "warn") + '<div class="muted" style="font-size:11.5px">' + O.esc(s.task.cli ? t("wb.sys.task_last", { at: s.task.cli.slice(0, 16) }) : t("wb.sys.task_never")) + "</div>"]);
    rows.push([t("wb.sys.missing"), s.missing_total ? link("/system/i18n", '<b style="color:var(--bad)">' + s.missing_total + "</b>") + '<div class="muted mono" style="font-size:11px">' +
      s.missing.map(function (m) { return O.esc(m.lang + " · " + m.key); }).join("<br>") + "</div>" : O.pill(t("wb.sys.missing_none"), "ok")]);
    return '<div class="card"><div class="card-h">' + O.esc(t("wb.system")) + '</div><div class="card-b"><table class="tbl sm wb-sys"><tbody>' +
      rows.map(function (r) { return "<tr><td>" + O.esc(r[0]) + "</td><td>" + r[1] + "</td></tr>"; }).join("") + "</tbody></table></div></div>";
  }

  function render(d) {
    view.innerHTML = '<div class="kpis">' + d.kpis.map(function (k) {
      return '<a class="kpi' + (k.bad ? " bad" : "") + '" href="' + O.esc(O.url(k.href)) + '" style="color:inherit;text-decoration:none"><div class="k">' + O.esc(t("wb.kpi." + k.k)) + '</div><div class="v">' + O.esc(String(k.v)) + "</div></a>";
    }).join("") + '</div><div class="split wb-split"><div id="wb_main" style="display:grid;gap:12px;min-width:0"></div><div id="wb_side" style="display:grid;gap:12px;align-content:start"></div></div>';
    var main = view.querySelector("#wb_main");
    if (d.kind === "unit") {
      main.appendChild(deptsCard(d.depts));
      main.appendChild(plansCard(t("wb.key_plans"), d.plans, true));
    } else {
      main.appendChild(membersCard(d.members));
      main.appendChild(plansCard(t("wb.dept_plans"), d.plans, false));
    }
    view.querySelector("#wb_side").innerHTML = side(d);
  }

  O.api("GET", "/api/v1/workbench").then(render, O.failed);
})();
