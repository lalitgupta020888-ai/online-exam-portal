# Online Exam Portal

A complete online examination / quiz system for **any number of colleges**. Each
college registers itself, and from then on its administrator, faculty and
students only ever see their own institution's data.

Three roles inside every college:

* **Students** register, sit timed exams and get instant results with a full
  answer review.
* **Faculty** create exams, set questions by typing them or importing a PDF /
  Word / text question paper, choose exactly which students may attempt each
  exam, mark the written answers, and get the whole class's results plus
  detailed analysis once everyone has finished.
* **Administrators** supervise the portal. They do **not** write papers or
  questions - they approve faculty, analyse how each faculty sets and marks
  work, inspect any question paper or answer sheet, and manage students and
  results.

Exams support **objective** questions (multiple choice, true/false, marked
automatically) and **subjective** questions (written answers, marked by the
faculty).

---

## 1. Technology

| Layer     | Technology |
|-----------|------------|
| Frontend  | HTML5, CSS3, Bootstrap 5, Bootstrap Icons, Plus Jakarta Sans, vanilla JavaScript (ES5, no build step) |
| Backend   | PHP 8 (PDO, prepared statements) |
| Database  | MySQL / MariaDB (InnoDB, foreign keys) |
| Server    | Apache via XAMPP |

**Note on Next.js/React.** The brief listed both Next.js/React and PHP + MySQL
on XAMPP. Those two cannot both be true: XAMPP serves PHP through Apache and
has no Node runtime, so a Next.js app cannot run there. This project is built
on the PHP + MySQL stack so it runs on a stock XAMPP install with no extra
tooling. The frontend is Bootstrap 5 with plain JavaScript; the exam screen is
a single page app driven by `assets/js/exam.js` talking to the JSON endpoints
in `api/`.

---

## 2. Setup with XAMPP

1. **Install and start XAMPP.** Open the XAMPP Control Panel and start
   **Apache** and **MySQL**.

2. **Copy the project into htdocs.** Place the whole folder inside the XAMPP
   web root so the path becomes:

   ```
   C:\xampp\htdocs\online-exam\
   ```

   (Any folder name works - the code detects its own base URL.)

3. **Check the database credentials.** Open `config/config.php`. The defaults
   match a stock XAMPP install:

   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_USER', 'root');
   define('DB_PASS', '');       // empty password
   ```

4. **Run the installer.** Visit:

   ```
   http://localhost/online-exam/install.php
   ```

   Press **Run Installation**. This creates the `online_exam` database, all
   eight tables, three demo exams with 40 questions, the administrator account
   and a demo student.

   *Alternative:* import `database/schema.sql` through phpMyAdmin, then still
   run `install.php` once so the password hashes are generated.

5. **Delete `install.php`** once installation succeeds.

6. **Open the site:** <http://localhost/online-exam/>

### Default accounts

| Role          | Username / Email        | Password     | Login page           |
|---------------|-------------------------|--------------|----------------------|
| Administrator | `admin`                 | `admin123`   | `/admin/login.php`   |
| Faculty       | `faculty`               | `faculty123` | `/faculty/login.php` |
| Demo student  | `student@example.com`   | `student123` | `/login.php`         |

Faculty can sign in with either the username or the email
(`faculty@example.com`). Change these passwords after the first login - every
role now has a **Change password** screen:

| Role    | Where |
|---------|-------|
| Student | Avatar menu → *My Account* → *Change password* (`/profile.php?tab=password`) |
| Faculty | Sidebar → *My Profile* → *Change password* (`/faculty/profile.php?tab=password`) |
| Admin   | Sidebar → *My Account* (`/admin/account.php`) |

### Colleges, branches and classes

**A college registers itself at `/admin/register.php`** (linked from the admin
login page and the site footer). The form collects the institution - name, short
code, **logo**, email, phone, website, address, city, state - together with its
first administrator account. Nine common branches (CSE, IT, ECE, EE, ME, CE,
BCA, MCA, BBA) are created automatically and can be edited from
**Admin → Branches**.

Faculty and students then register **under a college**:

| Role    | What they choose at sign-up |
|---------|-----------------------------|
| Student | College, **branch**, **current year**, **current semester**, enrolment number, admission year |
| Faculty | College, primary branch, department, designation, qualification... |

That class information is what makes allotment and analysis practical:

* A paper carries an intended audience - **branch / year / semester** - set on
  the exam form.
* The allotment screen filters the roster by branch, year and semester, with
  **Select all shown** and a **Select the paper's class** shortcut, so a whole
  class is allotted in two clicks instead of fifty.
* Every roster (admin students, faculty student analysis) has the same filter
  bar, and shows class chips next to each name.

**The tenant boundary.** `current_college_id()` in `includes/auth.php` is the
one place the boundary is defined, and every listing query filters on it. A
second college's admin cannot see - or reach by editing a URL - the first
college's students, faculty, papers, results, answer sheets or branches; a
faculty member cannot allot a paper to a student outside their college, and a
student cannot pick a branch that belongs to another college. All of that is
covered by the test suite.

**Logos** are uploaded to `uploads/logos/`. The file is validated by reading its
image header (not the file name), capped at 2 MB, stored under a random name,
and `uploads/.htaccess` switches the PHP engine off for that folder so an
uploaded file can never be executed.

### Faculty registration

Faculty sign themselves up at **`/faculty/register.php`** (linked from the
faculty login page). The form collects the full professional profile in three
sections - personal details, academic details and sign-in details:

> name, employee ID, official email, phone, department, designation,
> highest qualification, subjects/specialisation, teaching experience,
> date of joining, a short bio, username and password.

**New registrations need administrator approval.** A faculty account can set
papers and read every allotted student's marks, so a self-registered account is
created as `pending` and cannot sign in until an administrator approves it from
**Admin → Faculty** (the sidebar shows a badge with the number waiting). From
that screen the admin can also suspend, reinstate, issue a temporary password,
or remove a faculty member - removing one keeps their exams and results.

If you would rather let faculty in immediately, flip one line in
`config/config.php`:

```php
define('FACULTY_SELF_APPROVE', true);
```

### Upgrading an installation made before the faculty module

If the database was created by an older copy of this project, do **not** re-run
`install.php` (it recreates the tables and wipes your data). Instead visit:

```
http://localhost/online-exam/upgrade.php
```

It only adds the new tables and columns, creates the demo faculty account and
leaves every existing exam, student and result untouched. It is safe to run
more than once. Delete `upgrade.php` afterwards.

> The pages load Bootstrap from a CDN, so keep an internet connection on the
> first run (the browser caches it afterwards). To go fully offline, download
> `bootstrap.min.css`, `bootstrap.bundle.min.js` and `bootstrap-icons.css`
> into `assets/` and point the `<link>`/`<script>` tags at the local copies.

---

## 2b. Design system

The whole portal shares one visual language, defined as CSS custom properties
at the top of `assets/css/style.css`:

* **Palette** - deep indigo/midnight chrome (`--oep-grad-ink`) with an indigo →
  sky brand gradient and a champagne gold accent (`--oep-gold`) reserved for
  premium moments: the brand mark, the active sidebar item, the footer rule and
  the primary call to action.
* **Type** - Plus Jakarta Sans with tightened tracking on headings; content
  surfaces stay light and high contrast so a paper is comfortable to read for a
  full hour.
* **Elevation** - one four-step shadow scale (`--oep-shadow-xs` → `-lg`) and one
  radius scale, so every card, modal and input feels like the same product.
* **Motion** - a single easing pair, used for hover lifts, the timer pulse and
  the score ring fill. All of it is disabled under
  `prefers-reduced-motion: reduce`.

Changing a brand colour is a one-line edit to `:root` - nothing hard-codes a
hex value.

---

## 3. Folder structure

```
online-exam/
├── index.php                  Home page (hero, features, exam preview)
├── register.php               Student registration + validation
├── login.php                  Student login
├── forgot_password.php        Request a password reset token
├── reset_password.php         Set a new password from the token
├── logout.php                 End the student session
├── profile.php                Student account: details + change password
├── dashboard.php              Exam dashboard, grouped by subject
├── instructions.php           General + per exam instructions, accept checkbox
├── start_exam.php             Creates the attempt and starts the server timer
├── exam.php                   The examination interface
├── result.php                 Result dashboard for one attempt
├── review.php                 Answer review (question by question)
├── results.php                History of all the student's results
├── download_result.php        Printable result sheet (Print / Save as PDF)
├── install.php                One click installer - delete after setup
├── upgrade.php                Faculty module upgrade - delete after setup
│
├── api/                       JSON endpoints used by the exam screen
│   ├── save_answer.php          Autosave one answer / written text / review flag
│   ├── submit_exam.php          Grade + close the attempt
│   └── time_left.php            Authoritative remaining time
│
├── faculty/
│   ├── login.php                Faculty login (username or email)
│   ├── register.php             Faculty self registration (full profile)
│   ├── profile.php              My profile: details + change password
│   ├── logout.php
│   ├── index.php                Dashboard: completion tracker, results ready
│   ├── exams.php                My exams, publish toggle, release results
│   ├── exam_form.php            Create / edit an exam, choose the access mode
│   ├── questions.php            Question list for one of my exams
│   ├── question_form.php        Type an MCQ / True-False / written question
│   ├── import.php               Import from PDF / DOCX / TXT or pasted text
│   ├── assign.php               Choose which students may attempt the exam
│   ├── evaluate.php             Award marks and feedback for written answers
│   ├── results.php              Class result sheet (locked until all finish)
│   ├── analysis.php             Class analysis: distribution, toppers, per question
│   ├── students.php             Students assigned to my exams
│   ├── student_analysis.php     One student's complete record and trend
│   ├── print_results.php        Printable / PDF result sheet
│   └── includes/
│       ├── faculty_header.php   Faculty layout + faculty guard
│       ├── faculty_footer.php
│       └── .htaccess            Blocks direct access
│
├── admin/
│   ├── login.php                Administrator login
│   ├── logout.php
│   ├── index.php                Dashboard + examination statistics
│   ├── exams.php                List / publish / delete exams
│   ├── exam_form.php            Add or edit an exam
│   ├── questions.php            Question bank with filters
│   ├── question_form.php        Add or edit a question and its options
│   ├── faculty.php              Approve / suspend / manage faculty accounts
│   ├── faculty_analysis.php     Compare every faculty: output, marking, outcomes
│   ├── faculty_detail.php       One faculty in depth + their recent marking
│   ├── papers.php               Every question paper: inspect, allot, transfer
│   ├── paper_view.php           Read-only question paper with the answer key
│   ├── attempt_view.php         Read-only answer sheet + how it was marked
│   ├── assign.php               Allot a paper to students
│   ├── account.php              Change the administrator password
│   ├── students.php             Student list, search, block, delete
│   ├── results.php              All results with filters
│   └── includes/
│       ├── admin_header.php     Admin layout + admin guard
│       ├── admin_footer.php
│       └── .htaccess            Blocks direct access
│
├── config/
│   ├── config.php               DB credentials, app constants, timezone
│   ├── db.php                   PDO connection (singleton)
│   └── .htaccess                Blocks direct access
│
├── includes/
│   ├── auth.php                 Sessions, CSRF, student/faculty/admin guards
│   ├── functions.php            Helpers, access rules + the scoring engine
│   ├── doc_text.php             PDF / DOCX / TXT text extraction (no libraries)
│   ├── question_parser.php      Turns question paper text into questions
│   ├── migrate_faculty.php      Faculty module migration (idempotent)
│   ├── sql_util.php             SQL splitter + schema introspection helpers
│   ├── header.php               Public layout header + navbar
│   ├── footer.php               Public layout footer
│   └── .htaccess                Blocks direct access
│
├── assets/
│   ├── css/style.css            Theme on top of Bootstrap 5
│   └── js/
│       ├── main.js              Form validation, confirm dialogs, search
│       └── exam.js              Exam engine: timer, palette, autosave, submit
│
└── database/
    ├── schema.sql               Tables, relationships and demo data
    └── upgrade_faculty.sql      Faculty module tables
```

---

## 4. Database design

Eleven tables with foreign keys and `ON DELETE CASCADE`:

```
faculty ──< exams >── admins (exams may also be admin owned)
   │          │
   │          ├──< questions ──< options
   │          │         │            │
   │          ├──< exam_assignments  │   (who may attempt)
   │          ├──< question_imports  │   (import history)
   │          │                      │
students ──< attempts >──────────────┘
   │            │
   │            └──< answers (option OR written text + awarded marks)
   │
   └──< results (1 : 1 with attempts)
```

| Table              | Purpose |
|--------------------|---------|
| `students`         | Student accounts plus their **college, branch, year, semester**, enrolment number and admission year |
| `admins`           | Administrator accounts |
| `colleges`         | One row per institution: name, code, logo, contact and address |
| `branches`         | Branches / departments a college offers |
| `faculty`          | Faculty accounts with the full professional profile (employee ID, phone, department, designation, qualification, specialisation, experience, joining date, bio) plus `status` = pending / approved / suspended |
| `exams`            | Title, subject, duration, marks per question, negative marking, passing marks, owner (`faculty_id`), `access_mode`, `results_released` |
| `exam_assignments` | Which students may attempt which exam |
| `questions`        | Text, type (`mcq` / `truefalse` / `subjective`), category, difficulty, marks, explanation, model answer, word limit |
| `options`          | Choices per objective question with the `is_correct` flag |
| `question_imports` | One row per PDF / DOCX / text import, with how many questions it produced |
| `attempts`         | One row per started exam: `started_at`, `expires_at`, `submitted_at`, status, `evaluation_status` |
| `answers`          | One row per (attempt, question): chosen option **or** written text, review flag, correctness, awarded marks, faculty feedback |
| `results`          | Graded summary split into objective / subjective marks, `UNIQUE` on `attempt_id` |

Total marks for an exam are always derived as `SUM(questions.marks)`, so
adding or removing a question keeps the paper consistent automatically.

---

## 5. How the important parts work

### Countdown timer

* `start_exam.php` computes `expires_at = now + duration` **once** and stores
  it on the attempt row.
* `exam.php` renders the remaining seconds from that stored deadline, so a
  page refresh, a new tab or a browser restart cannot buy extra time.
* `assets/js/exam.js` counts down locally for smooth display and re-syncs with
  `api/time_left.php` every 30 seconds (covers throttled tabs and sleep).
* The box turns amber at 5 minutes and red with a pulse in the last minute.
* At `00:00` the paper is submitted automatically. Even if the browser is
  closed, the attempt is graded the next time the student opens the dashboard
  or the deadline is polled.

### Scoring

All scoring happens in `grade_attempt()` in `includes/functions.php`:

1. Loads every question of the exam with the student's answer joined in.
2. Counts correct / incorrect / unanswered, adds the question's marks for a
   correct answer and subtracts the exam's negative marks for a wrong one
   (the total is floored at zero).
3. Computes percentage and PASS/FAIL against `exams.passing_marks`.
4. Inserts the `results` row and closes the attempt - inside a transaction
   with `SELECT ... FOR UPDATE`, so two parallel submissions cannot produce
   two results.

### Who can attempt which exam

**A student sees only the papers allotted to them - nothing else exists for
them anywhere in the portal.** There is no "open to everyone" mode.

* The faculty (or the administrator) ticks the students who may attempt a paper
  on the **Assign / Allot** screen, which writes to `exam_assignments`.
* The dashboard lists only those papers, **grouped subject by subject**.
* The rule is enforced in one place, `student_can_attempt()`, and every route
  goes through it - the dashboard listing, the instructions page and
  `start_exam.php` - so a paper that was never allotted cannot be reached by
  editing the URL or by posting the form directly.
* An unallotted student is not merely hidden from the button; the exam is
  absent from their exam list query altogether.

A student who has already started an exam keeps their allotment even if the
faculty later edits the list, so an in-progress paper is never stranded.

Admin created exams follow exactly the same rule - allot them from
**Admin → Manage Exams → the people icon**, or they stay invisible.

### Importing a question paper

`faculty/import.php` accepts a **PDF**, **Word (.docx)** or **text** file, or
pasted text, and runs in three stages: read → preview and edit → save. Nothing
touches the database until the faculty confirms the preview, so a badly
formatted file can never corrupt a question bank.

Text extraction is written from scratch in `includes/doc_text.php` - it needs
no Composer packages and no PHP extension beyond zlib:

* **PDF** - inflates the content streams and walks the text showing operators
  (`Tj`, `TJ`, `'`, `"`), applying any `/ToUnicode` CMap the file contains.
* **DOCX** - reads `word/document.xml` using a small built in ZIP reader,
  because `ext-zip` is disabled in a default XAMPP build.

The recognised layout is forgiving about numbering (`Q1.`, `1.`, `1)`) and
option markers (`A)`, `A.`, `(A)`):

```
Q1. What is the full form of HTML?
A) Hyper Text Markup Language
B) High Text Machine Language
C) Hyperlink Text Management Language
D) High-level Text Markup Language
Answer: A
Marks: 1
Category: HTML
Difficulty: easy
Explanation: HTML stands for Hyper Text Markup Language.

Q2. The CPU is an output device. (True/False)
Answer: False

Q3. [Subjective] Explain the difference between RAM and ROM.
Marks: 5
Model Answer: RAM is volatile read/write memory; ROM is non volatile.
```

A question with no options becomes a written (subjective) question
automatically; `Answer:`, `Marks:`, `Explanation:`, `Category:` and
`Difficulty:` are all optional. Anything the parser is unsure about is flagged
in the preview instead of being guessed at.

**Limit:** a scanned PDF (a photograph of a page) contains no text at all, and
no parser can recover it - that needs OCR. The importer detects this and asks
you to paste the questions instead.

### Written answers and evaluation

Subjective questions show a text box with a live word counter instead of
options, and autosave a moment after typing stops. When such a paper is
submitted:

1. The objective part is scored immediately, exactly as before.
2. The result is stored with `pending_evaluation = 1`, and the student sees
   *"awaiting evaluation"* with their objective marks so far.
3. The paper appears in the faculty's **Evaluate Answers** queue, showing the
   student's answer beside the faculty's own model answer.
4. Marks awarded are clamped to the question maximum, and `recalc_result()`
   folds them into the total and recomputes pass/fail.
5. The student then sees the final score plus the faculty's feedback per
   question in their answer review.

### When results become visible

The class result sheet and analysis for an exam stay locked until **every
assigned student has completed it**. Until then the faculty sees a progress
tracker (completed / writing now / not started) and the roster of who is still
outstanding. A **Release results now** button unlocks them early when someone
never turns up.

### Faculty analysis (administrator view)

`faculty_analytics()` in `includes/functions.php` computes one metric set per
faculty member, and both admin screens render it:

| Metric | What it tells the administrator |
|--------|----------------------------------|
| Papers / questions / written questions | How much this faculty has actually authored |
| Students reached | How many students their papers were allotted to |
| Marking backlog + age of the oldest | Whether students are stuck on a provisional score |
| **Marking generosity** | Share of the available subjective marks they award - under 40% reads as strict, over 85% as lenient |
| Full-marks % / zero-marks % | Whether they mark in extremes or use the range |
| Feedback coverage | How often they actually write feedback for the student |
| Turnaround | Average hours between submission and marking |
| Pass rate and average score | Whether the papers are pitched right (95%+ or under 30% is flagged) |

`faculty_observations()` turns those numbers into plain sentences, which is
what the *Things worth a look* panel and the per-faculty *Assessment* card show.

### Class analysis (faculty view)

* **Class analysis** (`faculty/analysis.php`) - average, median, standard
  deviation, highest/lowest, pass rate, a score distribution across five bands,
  the top five students, and a per question success rate that shows which
  topics the class struggled with.
* **Student analysis** (`faculty/student_analysis.php`) - how many exams the
  student has taken, their marks trend drawn as an inline SVG chart, subject
  wise strength, topic wise accuracy, the correct/wrong/skipped split across
  every exam, and the full exam history.
* Both can be printed or saved as PDF via `faculty/print_results.php`.

### Security

* Passwords stored with `password_hash()` (bcrypt) and checked with
  `password_verify()`.
* Faculty can only see and change **their own** exams, questions, results and
  evaluations - ownership is checked with `get_owned_exam()` on every page, not
  just hidden in the UI.
* A model answer is never sent to a student's browser, during or after the exam.
* Self-registered faculty accounts stay inactive until an administrator
  approves them.
* Changing a password requires the current one, and re-issues the session id.
  Suspending a faculty member ends their open session on the next request -
  the guard drops the stale session instead of bouncing between login and
  dashboard.
* Every database access uses PDO prepared statements.
* Every POST form and JSON call carries a CSRF token.
* `require_student()` / `require_admin()` guard every protected page; session
  ids are regenerated on login and rotated every 30 minutes.
* The `is_correct` flag is never sent to the browser while an exam is running -
  the answer key only appears on `review.php`, after submission.
* Scores are calculated on the server only; the client can post answers but
  never a score.
* A submitted attempt cannot be reopened, and `results.attempt_id` is UNIQUE.
* `.htaccess` files block direct access to `config/`, `includes/` and
  `admin/includes/`.
* All output goes through `e()` (`htmlspecialchars`) to prevent XSS.

---

## 6. Using the portal

**As a student**

1. Register, then log in.
2. Pick an exam on the dashboard → read the instructions → tick
   *"I have read and understood all instructions"* → **Start Exam**.
3. Answer questions (answers save automatically), use **Mark for Review**,
   **Clear Answer** and the palette to navigate.
4. **Submit Exam** → confirm → the result dashboard appears instantly.
5. **Review Answers** to compare your answer with the correct one and read the
   explanation; **Download Result** opens a printable statement.

Keyboard shortcuts during an exam: `←` / `→` move between questions,
`1`“`4` select an option.

**As a faculty member**

1. Log in at `/faculty/login.php`.
2. **Create Exam** → set duration, marks, negative marking, passing marks, and
   leave the access mode on *"Only the students I assign"*.
3. Add questions either by **typing** them (MCQ, True/False or written) or with
   **Import** → upload the question paper PDF → check the preview → Import.
4. **Assign** → tick the students who may take this exam and save. Nobody else
   can see or start it.
5. Watch the completion tracker on the dashboard. When the last student
   submits, the exam moves to **Results ready**.
6. If the paper had written questions, **Evaluate Answers** shows each answer
   next to your model answer; award marks and optional feedback.
7. **All Results** for the class sheet, **Analysis** for the class breakdown,
   **Print / PDF** for a printable sheet, and **Student Analysis** for one
   student's complete record.

**As an administrator**

The administrator supervises; the faculty author. There is deliberately no
"add exam" or "add question" screen in the admin panel.

1. Log in at `/admin/login.php`. The dashboard opens with a **Needs attention**
   panel: faculty waiting for approval, unmarked papers, papers with no owner
   and papers not yet allotted.
2. *Faculty Accounts* → approve a new registration, suspend or reinstate
   someone, issue a temporary password, or remove an account.
3. *Faculty Analysis* → one row per faculty comparing papers set, questions
   authored, students reached, marking backlog, **marking generosity**
   (strict / balanced / lenient), feedback rate, turnaround and pass rate.
   Sort by any of them; the page ends with *"Things worth a look"*.
4. Click **Analyse** on a faculty for the deep dive: their papers with class
   averages, their question bank by type / difficulty / topic, how they mark
   written answers, the papers they still owe, and their most recent marking
   with the marks and feedback they gave.
5. *Question Papers* → inspect any paper. **View questions** shows the whole
   paper read-only, including the correct answers, explanations and model
   answers, plus how the class performed on each question. From here you can
   also allot it, transfer an ownerless paper to a faculty member, hide it or
   delete it.
6. From any results table, open the **answer sheet** of a single attempt to see
   what the student wrote, the marks the faculty awarded, who marked it, when,
   and the feedback they left.
7. *Students* → search, block or delete students. *Results* → filter every
   submitted paper by exam, outcome or student.

---

## 7. Troubleshooting

| Problem | Fix |
|---------|-----|
| "Database connection failed" | Start MySQL in XAMPP, then run `install.php`. |
| Installer reports access denied | Set the MySQL user/password in `config/config.php`. |
| Page loads without styling | You are offline - the Bootstrap CDN is unreachable. Use local copies as described above. |
| Links point at the wrong folder | Set `BASE_URL` manually in `config/config.php`, e.g. `define('BASE_URL', '/online-exam');` |
| Times look shifted | Change `date_default_timezone_set()` in `config/config.php`. |
| Faculty pages 404 | The faculty module was added later - run `upgrade.php` once. |
| PDF import reads nothing | The PDF is a scan (an image). Paste the text, or upload a .docx / .txt instead. |
| PDF import reads gibberish | The PDF uses an embedded font with no Unicode map. Fix the questions in the preview, or paste the text. |
| Import file rejected | Only `.pdf`, `.docx` and `.txt` up to 8 MB are accepted (see `MAX_UPLOAD_BYTES` in `faculty/import.php`). |
