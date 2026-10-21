# Apsara Vimana

Agente local / comunitario del proyecto **Vimana** (El Salvador).  
Motor PHP estilo YOCO + orquestador de búsqueda **100 % gratuito** (sin APIs de pago).

Repositorio pensado para **GitHub → Render** (Docker).

## Estructura

```
Apsara_Vimana/
├── index.html              # Chat
├── js/apsara.js            # Front del chat
├── api/engine.php          # Motor (YOCO + robot de búsqueda + matemáticas)
├── glosarios/              # Glosario maestro TXT
├── data/ayurveda.json      # Base local Ayurveda (ejemplo)
├── cache/                  # Caché de respuestas externas (escribible)
├── imagenes/estados/       # Avatares de estado (sube tus JPG)
├── imagenes/personas/      # Fotos de maestros
├── Dockerfile
├── .env.example
└── README.md
```

## Cascada del robot (gratis)

1. **Local** — glosario, maestros, música Vimana, biblioteca, caché, **calculadora**
2. **SearXNG** — opcional (`SEARXNG_URL`); si falla se salta
3. **DuckDuckGo** Instant Answer (sin key)
4. **Wikipedia** — último recurso (no en actualidad / ciencia / software / medicina…)
5. **Botón Yahoo** — siempre disponible en el chat

## Matemáticas

Ejemplos: `cuanto es 15*3+2`, `calcula sqrt(144)`, `2^10`

## Despliegue en Render

1. Sube este repo a GitHub: `Apsara-Vimana/Apsara_Vimana`
2. En Render: **New → Web Service →** conecta el repo
3. Runtime: **Docker**
4. Variables opcionales:
   - `SEARXNG_URL` = URL de una instancia que acepte `format=json`
5. Deploy

Las carpetas `cache/` deben ser escribibles (el Dockerfile ya lo configura).

## Imágenes

Coloca en el repo:

- `imagenes/estados/2.jpg`, `Atento.jpg`, `Pensando.jpg`, `Reconectando.jpg`, …
- `imagenes/personas/sadh_guru.jpg`, etc. (nombres según `PERSONAS` en `engine.php`)

Sin esas fotos el chat funciona; solo fallarán los avatares.

## Local (opcional)

```bash
docker build -t apsara-vimana .
docker run -p 8080:80 apsara-vimana
# abrir http://localhost:8080
```

## Licencia / uso

Servicio comunitario del proyecto Vimana. No sustituye consejo médico ni espiritual profesional.
