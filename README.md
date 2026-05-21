# Procobro Astro Landing

Landing page built with [Astro](https://astro.build/).

## Getting Started

Install dependencies and run the development server:

```bash
npm install
npm run dev
```

Open [http://localhost:4321](http://localhost:4321) with your browser to see the result.

You can start editing the page by modifying `src/pages/index.astro` and the components in `src/components/`. The page auto-updates as you edit files.

## Commands

```bash
npm run dev
npm run build
npm run preview
```

## Contact Form on cPanel FTP

The contact endpoint lives in `public/api/contact.php`. Astro copies everything in
`public/` into `dist/`, so after `npm run build` the endpoint is available as:

```txt
dist/api/contact.php
```

Before uploading, create the real SMTP config from the example:

```bash
cp public/api/contact.config.example.php public/api/contact.config.php
```

Then edit `public/api/contact.config.php` with the SMTP host, port, user, password,
sender, and destination email. This file is ignored by git.

For cPanel FTP deploys:

1. Run `npm run build`.
2. Upload the contents of `dist/` to `public_html/`.
3. Make sure these files exist on the server:
   - `public_html/api/contact.php`
   - `public_html/api/contact.config.php`

The endpoint can send through SMTP natively. If PHPMailer is uploaded later, it
will use PHPMailer automatically from either `api/vendor/autoload.php` or
`api/PHPMailer/src/`.

## Learn More

- [Astro Documentation](https://docs.astro.build)
