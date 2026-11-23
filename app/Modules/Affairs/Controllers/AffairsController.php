<?php
namespace Omps\Modules\Affairs\Controllers;

use Omps\Core\Controller;
use Omps\Core\Request;
use Omps\Modules\Affairs\Services\IssueService;
use Omps\Modules\Affairs\Services\NoteService;
use Omps\Modules\Affairs\Services\NotificationService;
use Omps\Modules\Affairs\Services\RuleService;
use Omps\Modules\Affairs\Services\TodoService;

class AffairsController extends Controller
{
    // ---------- pages ----------
    public function todoPage(Request $req)
    {
        return $this->renderPage($req, 'Affairs::page', 'menu.aff_todo', array(), array('todo'));
    }

    public function notifyPage(Request $req)
    {
        return $this->renderPage($req, 'Affairs::page', 'menu.aff_notify', array(), array('notify'));
    }

    public function notesPage(Request $req)
    {
        return $this->renderPage($req, 'Affairs::page', 'menu.aff_notes', array(), array('notes'));
    }

    public function issuesPage(Request $req)
    {
        return $this->renderPage($req, 'Affairs::page', 'menu.aff_issues', array(), array('issues'));
    }

    public function rulesPage(Request $req)
    {
        return $this->renderPage($req, 'Affairs::page', 'menu.reminders', array(), array('rules'));
    }

    // ---------- bell, to-dos, notifications, preferences ----------
    public function pulse(Request $req)
    {
        return NotificationService::pulse($req);
    }

    public function todo(Request $req)
    {
        $t = TodoService::build($req->user);
        return array('rows' => $t['rows'], 'counts' => $t['counts']);
    }

    public function notifications(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return NotificationService::index($req, $req->str('tab') === 'unread' ? 'unread' : 'all', $page, $size);
    }

    public function readOne(Request $req)
    {
        return NotificationService::readOne($req, $this->idParam($req));
    }

    public function readAll(Request $req)
    {
        return NotificationService::readAll($req);
    }

    public function prefs(Request $req)
    {
        return NotificationService::prefs($req->userId());
    }

    public function savePrefs(Request $req)
    {
        return NotificationService::savePrefs($req, $req->body);
    }

    // ---------- notebook ----------
    public function notesMeta(Request $req)
    {
        return NoteService::meta($req);
    }

    public function notes(Request $req)
    {
        $box = $req->str('box') === 'inbox' ? 'inbox' : 'mine';
        return NoteService::index($req, $box, $req->str('kw'), $req->str('filter'));
    }

    public function note(Request $req)
    {
        return NoteService::show($req, $this->idParam($req));
    }

    public function createNote(Request $req)
    {
        return NoteService::create($req, $req->body);
    }

    public function updateNote(Request $req)
    {
        return NoteService::update($req, $this->idParam($req), $req->body);
    }

    public function deleteNote(Request $req)
    {
        return NoteService::delete($req, $this->idParam($req));
    }

    public function submitNote(Request $req)
    {
        return NoteService::submit($req, $this->idParam($req));
    }

    public function withdrawNote(Request $req)
    {
        return NoteService::withdraw($req, $this->idParam($req));
    }

    public function readNote(Request $req)
    {
        return NoteService::read($req, $this->idParam($req), $req->body);
    }

    public function copyNote(Request $req)
    {
        return NoteService::copy($req, $this->idParam($req));
    }

    // ---------- issued items ----------
    public function issuesMeta(Request $req)
    {
        return IssueService::meta($req);
    }

    public function issues(Request $req)
    {
        list($page, $size) = $this->pageArgs($req);
        return IssueService::index($req, $req->str('box') === 'sent' ? 'sent' : 'in', $req->str('filter') === 'all' ? 'all' : 'pending', $page, $size);
    }

    public function issue(Request $req)
    {
        return IssueService::show($req, $this->idParam($req));
    }

    public function createIssue(Request $req)
    {
        return IssueService::create($req, $req->body);
    }

    public function readIssue(Request $req)
    {
        return IssueService::read($req, $this->idParam($req));
    }

    public function replyIssue(Request $req)
    {
        return IssueService::reply($req, $this->idParam($req), $req->body);
    }

    public function nudgeIssue(Request $req)
    {
        return IssueService::nudge($req, $this->idParam($req));
    }

    public function withdrawIssue(Request $req)
    {
        return IssueService::withdraw($req, $this->idParam($req));
    }

    // ---------- reminder rules ----------
    public function rules(Request $req)
    {
        return RuleService::index();
    }

    public function updateRule(Request $req)
    {
        return RuleService::update($req, $this->idParam($req), $req->body);
    }

    public function previewRule(Request $req)
    {
        return RuleService::preview($req->int('id'), $req->body);
    }
}
