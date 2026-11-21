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
