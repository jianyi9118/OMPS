/* 文件管理 (phase 9): 全部文件 · 我上传的 · 重复文件 · 回收站. Files are uploaded on the business pages only. */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var view = document.getElementById("view");
  var TABS = ["all", "mine", "dup", "trash"];
  var st = { tab: TABS.indexOf(O.qs("tab")) >= 0 ? O.qs("tab") : "all", path: [], kw: "", open: {} };
  var D = null;

  function load() {
    O.api("GET", "/api/v1/file-manager").then(function (d) { D = d; draw(); }, O.failed);
  }

  function kpis() {
    var k = function (label, v, tab) { return '<div class="kpi' + (tab ? " link" : "") + '"' + (tab ? ' data-go="' + tab + '"' : "") + '><div class="k">' + O.esc(label) + '</div><div class="v">' + O.esc(String(v)) + "</div></div>"; };
    return '<div class="kpis" id="fm_k">' + (D.used !== null ? k(t("fm.used"), O.fileSize(D.used)) : "") + k(t("fm.visible"), D.files.length, "all") + k(t("fm.mine"), D.mine, "mine") +
      k(t("fm.dup_groups"), D.dup_groups, "dup") + "</div>";
  }

  function draw() {
    view.innerHTML = kpis() + '<div class="subnav" style="border:1px solid var(--line);border-radius:4px" id="fm_tabs">' + TABS.map(function (k) {
      return '<button type="button" data-tab="' + k + '" class="' + (k === st.tab ? "on" : "") + '">' + O.esc(t("fm.tab." + k)) + "</button>"; }).join("") + '</div><div id="fm_body"></div>';
    view.querySelector("#fm_tabs").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-tab]") : null;
      if (b) { st.tab = b.getAttribute("data-tab"); st.path = []; draw(); }
    });
    view.querySelector("#fm_k").addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("[data-go]") : null;
      if (b) { st.tab = b.getAttribute("data-go"); st.path = []; draw(); }
    });
    ({ all: browse, mine: browse, dup: dups, trash: trash })[st.tab]();
  }

  function body() { return document.getElementById("fm_body"); }

  /* ---------- browse ---------- */
  function files() { return st.tab === "mine" ? D.files.filter(function (f) { return f.mine; }) : D.files; }

  function buildTree(list) {
    var root = { name: "", kids: {}, order: [], n: 0 };
    list.forEach(function (f) {
      var node = root;
      root.n++;
      f.dir.forEach(function (seg) {
        if (!node.kids[seg]) { node.kids[seg] = { name: seg, kids: {}, order: [], n: 0 }; node.order.push(seg); }
        node = node.kids[seg];
        node.n++;
      });
    });
    return root;
  }

  function same(a, b) { return a.length === b.length && a.every(function (x, i) { return x === b[i]; }); }
  function under(dir, path) { return path.every(function (x, i) { return dir[i] === x; }); }

  function treeHtml(node, path, depth) {
    return node.order.map(function (seg) {
      var k = node.kids[seg], p = path.concat([seg]), key = p.join("\u0001"), hasKids = k.order.length > 0;
      var open = st.open[key] || under(st.path, p);
      return '<li><div class="node' + (same(p, st.path) ? " on" : "") + '" data-p="' + O.esc(JSON.stringify(p)) + '" style="padding-left:' + (12 + depth * 14) + 'px">' +
        '<span class="tg" data-tg="' + O.esc(key) + '">' + (hasKids ? (open ? "▾" : "▸") : "") + "</span>" + O.icon("folder", 14) + " <span>" + O.esc(seg) + '</span><span class="cnt">' + k.n + "</span></div>" +
        (hasKids && open ? '<ul class="tree fmtree" style="padding:0">' + treeHtml(k, p, depth + 1) + "</ul>" : "") + "</li>";
    }).join("");
  }

  function browse() {
    var list = files();
    var tree = buildTree(list);
    var b = body();
    b.innerHTML = '<div class="split" style="grid-template-columns:minmax(240px,320px) minmax(0,1fr)"><div class="card"><ul class="tree fmtree" id="fm_tree">' +
      '<li><div class="node' + (st.path.length ? "" : " on") + '" data-p="[]"><span class="tg"></span>' + O.icon("folder", 14) + " <span>" + O.esc(t("fm.tab." + st.tab)) + '</span><span class="cnt">' + list.length + "</span></div></li>" +
      treeHtml(tree, [], 1) + '</ul></div><div class="card"><div class="card-h" style="flex-wrap:wrap;gap:8px"><input class="inp" id="fm_kw" style="max-width:280px" placeholder="' + O.esc(t("fm.search")) + '" value="' + O.esc(st.kw) + '">' +
      '<span class="muted" style="font-size:12.5px">' + O.esc(t("fm.upload_where")) + '</span></div><div class="card-b" style="padding-bottom:0"><div class="pd-crumb" id="fm_crumb"></div></div><div id="fm_list"></div></div></div>';
    b.querySelector("#fm_tree").addEventListener("click", function (e) {
      var tg = e.target.closest ? e.target.closest("[data-tg]") : null;
      if (tg && tg.textContent) { var k = tg.getAttribute("data-tg"); st.open[k] = !st.open[k]; browse(); return; }
      var n = e.target.closest ? e.target.closest("[data-p]") : null;
      if (n) { st.path = JSON.parse(n.getAttribute("data-p")); st.kw = ""; browse(); }
    });
    var kw = b.querySelector("#fm_kw");
    kw.addEventListener("input", function () { st.kw = kw.value.trim(); listPart(list, tree); });
    listPart(list, tree);
  }

  function nodeAt(tree, path) {
    var n = tree;
    for (var i = 0; i < path.length && n; i++) n = n.kids[path[i]];
    return n;
  }

  function listPart(list, tree) {
    var crumb = document.getElementById("fm_crumb"), box = document.getElementById("fm_list");
    var rows;
    if (st.kw) {
      var k = st.kw.toLowerCase();
      rows = list.filter(function (f) { return (f.name + " " + f.dir.join(" ")).toLowerCase().indexOf(k) >= 0; }).map(function (f) { return { file: f }; });
      crumb.textContent = t("fm.found", { n: rows.length });
    } else {
      var node = nodeAt(tree, st.path) || tree;
      rows = node.order.map(function (seg) { return { folder: seg, n: node.kids[seg].n }; })
        .concat(list.filter(function (f) { return same(f.dir, st.path); }).map(function (f) { return { file: f }; }));
      crumb.innerHTML = [t("fm.tab." + st.tab)].concat(st.path).map(function (s, i) { return i === st.path.length ? "<b>" + O.esc(s) + "</b>" : O.esc(s); }).join(" › ") +
        (D.root ? '<div class="mono" style="font-size:11.5px;margin-top:4px">' + O.esc([D.root].concat(st.path).join("\\")) + "\\</div>" : "");
    }
    box.innerHTML = "";
    box.appendChild(O.table({
      cols: [
        { t: t("fm.col.name"), f: function (r) {
          if (r.folder) return '<a href="#" data-open="' + O.esc(r.folder) + '">' + O.icon("folder", 14) + " " + O.esc(r.folder) + "</a>";
          return O.icon("file", 14) + " " + O.esc(r.file.name) + (r.file.dup > 1 ? " " + O.pill(t("fm.dup_n", { n: r.file.dup }), "warn") : "") + (st.kw ? '<div class="muted" style="font-size:12px">' + O.esc(r.file.dir.join(" › ")) + "</div>" : "");
        } },
        { t: t("fm.col.size"), cls: "num", f: function (r) { return r.folder ? O.esc(t("fm.n_files", { n: r.n })) : O.esc(O.fileSize(r.file.size)); } },
        { t: t("fm.col.uploader"), f: function (r) { return r.folder ? "" : O.esc(r.file.uploader) + '<div class="muted" style="font-size:12px">' + O.esc(r.file.at.slice(0, 16)) + "</div>"; } },
        { t: t("fm.col.links"), cls: "wrap", f: function (r) { return r.folder ? "" : O.esc(r.file.links.join(t("common.list_sep")) || "—"); } }
      ],
      rows: rows, empty: t("fm.empty"),
      acts: function (r) {
        if (r.folder) return [];
        var f = r.file;
        return [
          f.preview ? { icon: "eye", tip: t("fm.preview"), fn: function () { preview(f); } } : null,
          { icon: "download", tip: t("fm.download"), fn: function () { window.location.href = O.url("/api/v1/file-manager/" + f.id + "/download"); } },
          f.can_delete ? { icon: "trash", tip: t("common.delete"), danger: true, fn: function () { remove(f); } } : null
        ];
      }, slots: 3
    }));
    box.onclick = function (e) {
      var a = e.target.closest ? e.target.closest("[data-open]") : null;
      if (a) { e.preventDefault(); st.path = st.path.concat([a.getAttribute("data-open")]); browse(); }
    };
  }

  function preview(f) {
    var src = O.url("/api/v1/file-manager/" + f.id + "/download", { inline: 1 });
    if (!f.image) { window.open(src, "_blank"); return; }
    var box = document.createElement("div");
    box.innerHTML = '<img alt="" src="' + O.esc(src) + '" style="max-width:100%;max-height:70vh;display:block;margin:0 auto">';
    O.modal({ title: f.name, width: 900, body: box, buttons: [{ t: t("common.close") }] });
  }

  function remove(f) {
    O.confirm({ title: t("fm.delete"), msg: t("fm.delete_confirm", { name: f.name, days: 30, links: f.links.join(t("common.list_sep")) || "—" }), danger: true, ok: t("common.delete") }).then(function (ok) {
      if (ok) O.api("DELETE", "/api/v1/file-manager/" + f.id).then(function () { O.status(t("fm.deleted")); load(); }, O.failed);
    });
  }

  /* ---------- duplicates ---------- */
  function dups() {
    O.api("GET", "/api/v1/file-manager/duplicates").then(function (d) {
      var b = body();
      b.innerHTML = '<div class="note">' + O.esc(t("fm.dup_hint", { size: O.fileSize(d.saving) })) + "</div>";
      if (!d.groups.length) { b.insertAdjacentHTML("beforeend", '<div class="card"><div class="card-b muted">' + O.esc(t("fm.dup_none")) + "</div></div>"); return; }
      d.groups.forEach(function (g) {
        var card = document.createElement("div");
        card.className = "card";
        card.innerHTML = '<div class="card-h">' + O.icon("file", 14) + " " + O.esc(g.name) + '<span class="r muted">' + O.esc(t("fm.dup_copies", { n: g.copies.length, size: O.fileSize(g.size) })) + "</span></div>";
        card.appendChild(O.table({
          cols: [
            { t: t("fm.col.where"), cls: "wrap", f: function (c) { return c.hidden ? '<span class="muted">' + O.esc(t("fm.dup_hidden")) + "</span>" : O.esc(c.dir.join(" › ")) + '<div class="muted" style="font-size:12px">' + O.esc(c.links.join(t("common.list_sep"))) + "</div>"; } },
            { t: t("fm.col.uploader"), f: function (c) { return c.hidden ? "" : O.esc(c.uploader) + '<div class="muted" style="font-size:12px">' + O.esc(c.at.slice(0, 16)) + "</div>"; } },
            { t: t("cf.field.state"), f: function (c) { return c.kept ? O.pill(t("fm.kept"), "ok") : ""; } }
          ],
          rows: g.copies, empty: "",
          acts: function (c) { return [c.can_merge ? { icon: "merge", tip: t("fm.merge"), fn: function () { merge(c); } } : null]; }, slots: 1
        }));
        b.appendChild(card);
      });
    }, O.failed);
  }

  function merge(c) {
    O.confirm({ title: t("fm.merge"), msg: t("fm.merge_confirm"), ok: t("fm.merge") }).then(function (ok) {
      if (ok) O.api("POST", "/api/v1/file-manager/" + c.id + "/merge", {}).then(function () { O.status(t("fm.merged")); O.api("GET", "/api/v1/file-manager").then(function (d) { D = d; draw(); }); }, O.failed);
    });
  }

  /* ---------- recycle bin ---------- */
  function trash() {
    O.api("GET", "/api/v1/file-manager/trash").then(function (d) {
      var b = body();
      b.innerHTML = '<div class="note">' + O.esc(t("fm.trash_hint", { days: d.days })) + '</div><div class="card" id="fm_trash"></div>';
      b.querySelector("#fm_trash").appendChild(O.table({
        cols: [
          { t: t("fm.col.name"), f: function (f) { return O.icon("file", 14) + " " + O.esc(f.name) + '<div class="muted" style="font-size:12px">' + O.esc(O.fileSize(f.size)) + "</div>"; } },
          { t: t("fm.col.was"), cls: "wrap", f: function (f) { return O.esc(f.links.join(t("common.list_sep")) || "—"); } },
          { t: t("fm.col.deleted"), f: function (f) { return O.esc(f.deleted_at.slice(0, 16)) + '<div class="muted" style="font-size:12px">' + O.esc(f.deleter || "—") + "</div>"; } },
          { t: t("fm.col.left"), cls: "num", f: function (f) { return O.esc(t("fm.days_left", { n: f.days_left })); } }
        ],
        rows: d.rows, empty: t("fm.trash_empty"),
        acts: function (f) { return [f.restorable ? { icon: "restore", tip: t("fm.restore"), fn: function () { restore(f); } } : null]; }, slots: 1
      }));
    }, O.failed);
  }

  function restore(f) {
    O.api("POST", "/api/v1/file-manager/" + f.id + "/restore", {}).then(function () { O.status(t("fm.restored", { name: f.name })); O.api("GET", "/api/v1/file-manager").then(function (d) { D = d; draw(); }); }, O.failed);
  }

  load();
})();
