# API келісімі

Frontend пен backend арасындағы келісім. Екі жақ та осы құжатқа сай жазылады; өзгеріс алдымен
осында енгізіледі.

## Жалпы ережелер

- Барлық мекенжай `/api` деп басталады, жауап JSON.
- Кіру cookie арқылы жүреді (Laravel Sanctum, бір домен). Токен браузер жадында сақталмайды.
- `GET`-тен басқа сұраныс алдында `GET /sanctum/csrf-cookie` шақырылады, содан кейін
  `XSRF-TOKEN` cookie мәні `X-XSRF-TOKEN` тақырыбында жіберіледі.
- Қате жауабында машина оқитын `code` өрісі болады. Адамға арналған мәтін серверде
  жазылмайды: оны интерфейс үш тілдің бірінде көрсетеді.

## Жалпы қате кодтары

Кез келген `/api` жолы қайтаруы мүмкін:

| Күй | `code` | Мағынасы |
|---|---|---|
| 401 | `unauthenticated` | кірмеген, сеанс біткен не аккаунт өшірілген |
| 403 | `forbidden` | рөлге рұқсат жоқ |
| 403 | `password_change_required` | бір реттік пароль әлі ауыстырылмаған |
| 404 | `not_found` | мұндай жол жоқ |
| 419 | `csrf_mismatch` | CSRF белгісі жоқ не ескірген; интерфейс cookie-ді қайта алып, сұранысты қайталайды |
| 422 | `validation_failed` | сұраныс өрістері қате; `fields` тізімінде өріс атаулары |
| 429 | `too_many_attempts` | тым көп әрекет |

## Пайдаланушы нысаны

```json
{
  "id": 7,
  "full_name": "…",
  "role": "admin | teacher",
  "locale": "kk | ru | zh",
  "must_change_password": false,
  "homeroom_classes": ["7Ә"]
}
```

`homeroom_classes` — жетекшілік ететін сыныптардың атаулары; жетекші болмаса бос тізім.
3-кезеңге дейін (сыныптар кестесі жоқ кезде) әрдайым бос.

## Кіру

| Әдіс | Жол | Сұраныс | Сәтті жауап | Қате кодтары |
|---|---|---|---|---|
| GET | `/api/auth/me` | — | `200 {"user": …}` | `401 unauthenticated` |
| POST | `/api/auth/login` | `{"login", "password"}` | `200 {"user": …}` | `422 invalid_credentials`, `429 too_many_attempts` |
| POST | `/api/auth/logout` | — | `204` | `401 unauthenticated` |
| POST | `/api/auth/password` | `{"current_password", "new_password"}` | `200 {"user": …}` | `422 wrong_current_password`, `422 weak_password`, `422 same_password`, `401 unauthenticated` |
| POST | `/api/auth/locale` | `{"locale"}` | `200 {"user": …}` | `401 unauthenticated` |

Ережелер:

- Логин бас-кіші әріпке тәуелсіз салыстырылады, шетіндегі бос орындар алынады.
- Қате логин мен қате пароль бірдей `invalid_credentials` қайтарады (логиннің бар-жоғын
  ашпау үшін). Өшірілген аккаунт та солай.
- Бір логин мен бір IP үшін 5 қате әрекеттен кейін 5 минутқа `too_many_attempts`.
- Пароль ережесі: кемінде 8 таңба, ішінде әріп те, сан да бар. Жаңа пароль ескісінен өзгеше.
- `must_change_password = true` кезінде `/api/auth/*` жолдарынан басқа барлық жол
  `403 password_change_required` қайтарады.
- Сәтті кіруден кейін сеанс идентификаторы жаңартылады.
- Пароль ауысқанда сол аккаунттың басқа құрылғылардағы сеанстары жабылады.
- Аккаунт өшірілсе (`is_active = false`), оның ашық сеансы келесі сұраныста жабылады.

## Әкімші API (`/api/admin/…`)

Тек `admin` рөліне. Мұғалім `403 forbidden` алады. Күндер `YYYY-MM-DD` пішімінде.

### Оқу жылы және күнтізбе

| Әдіс | Жол | Сұраныс | Жауап | Қате кодтары |
|---|---|---|---|---|
| GET | `/academic-years` | — | `{"years": [year]}` (жаңасы бірінші) | |
| POST | `/academic-years` | `{"name", "start_date", "end_date"}` | `201 {"year"}` | `422 invalid_range`, `409 name_taken`, `409 dates_overlap` |
| POST | `/academic-years/{id}/make-current` | — | `{"year"}` | |
| GET | `/academic-years/{id}/calendar` | — | `{"year", "days": [day], "school_day_count"}` | |
| PUT | `/academic-years/{id}/calendar` | `{"from", "to", "is_school_day", "note"?}` | `{"changed": N}` | `422 invalid_range` |

`year`: `{"id", "name", "start_date", "end_date", "is_current"}`. `day`: `{"date", "is_school_day", "note"}`.

- Оқу жылы ашылғанда күнтізбе өзі жасалады: дүйсенбі–жұма оқу күні, сенбі мен жексенбі демалыс.
- Алғашқы оқу жылы бірден ағымдағы болады. Оқу жылдарының күндері қабаттаспайды.
- Каникул мен мереке күндер аралығы ретінде белгіленеді (`is_school_day: false`). Демалыс күнін
  оқу күні етуге де болады (ауыстырылған жұмыс күні).

### Сыныптар

| Әдіс | Жол | Сұраныс | Жауап | Қате кодтары |
|---|---|---|---|---|
| GET | `/classes?year_id=` | — (әдепкі: ағымдағы жыл) | `{"year", "classes": [class]}` | |
| POST | `/classes` | `{"name", "shift", "language"?, "homeroom_user_id"?}` | `201 {"class"}` | `422 invalid_class_name`, `409 class_exists`, `409 no_current_year`, `422 homeroom_invalid` |
| PATCH | `/classes/{id}` | жоғарыдағы өрістер + `"is_active"` | `{"class"}` | сол кодтар |

`class`: `{"id", "name", "grade", "letter", "shift": "morning|afternoon", "language": "kk|ru",
"is_active", "homeroom": {"id", "full_name"} | null, "student_count"}`.

- `name` мектепте жазылатындай: параллель (1–11) мен бір бас әріп, бос орынсыз (`7В`).
- Тізім параллель, содан кейін қазақ әліпбиі ретімен беріледі (9А, 9Ә, 9Б …).
- Сынып өшірілмейді, жабылады (`is_active: false`): тарихы мен есептері сақталады.

### Оқушылар

| Әдіс | Жол | Сұраныс | Жауап | Қате кодтары |
|---|---|---|---|---|
| GET | `/classes/{id}/students?date=` | — (әдепкі: бүгін) | `{"class", "date", "students": [student]}` | `422 invalid_date` |
| POST | `/classes/{id}/students` | `{"last_name", "first_name", "patronymic"?}` | `201 {"student"}` | `409 student_exists`, `422 class_closed` |
| PATCH | `/students/{id}` | аты-жөн өрістері | `{"student"}` | |
| POST | `/students/{id}/transfer` | `{"class_id", "date"?}` | `{"student"}` | `404 class_not_found`, `422 same_class`, `422 class_closed`, `422 not_enrolled`, `422 invalid_date`, `422 date_before_enrollment` |
| POST | `/students/{id}/withdraw` | `{"date"?}` (мектептегі соңғы күні) | `{"student"}` | `422 not_enrolled`, `422 invalid_date`, `422 date_before_enrollment` |

`student`: `{"id", "last_name", "first_name", "patronymic", "class_id"}`. Басқа жеке дерек сақталмайды.

- Тізім қазақ әліпбиі ретімен (журналдағыдай): тегі, аты, әкесінің аты.
- Аты-жөндегі артық бос орындар алынады. Бір сыныпта тегі, аты, әкесінің аты бірдей
  (бас-кіші әріпке қарамай) екінші оқушы қосылмайды.
- Көшіру `date` күнінен бастап күшіне енеді; оған дейінгі күндер ескі сыныпта қалады.
  Көшіру мен шығару болашақ күнге жазылмайды.

### Баптаулар

| Әдіс | Жол | Сұраныс | Жауап |
|---|---|---|---|
| GET | `/settings` | — | `{"settings": {…}}` |
| PATCH | `/settings` | өзгертілетін кілттер | `{"settings": {…}}` |

| Кілт | Әдепкі | Шегі | Мағынасы |
|---|---|---|---|
| `max_lessons_per_shift` | 7 | 1–12 | бір сменадағы ең көп сабақ саны |
| `homeroom_edit_days` | 14 | 0–90 | жетекші өткен жазбаны неше күнге дейін өзгерте алады |
| `pending_reason_days` | 3 | 1–30 | анықталмаған себеп неше оқу күнінен кейін бөлек көрсетіледі |
