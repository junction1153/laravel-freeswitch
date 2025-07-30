import React, { useRef, useState, useEffect, FormEvent } from "react";
import Head from "@docusaurus/Head";

type ContactFormProps = {
  workerEndpoint: string;
  turnstileSiteKey: string;
};

declare global {
  interface Window {
    turnstile?: {
      render: (el: HTMLElement, opts: { sitekey: string }) => string;
      reset: () => void;
    };
  }
}

const ContactForm: React.FC<ContactFormProps> = ({
  workerEndpoint,
  turnstileSiteKey,
}) => {
  const [status, setStatus] = useState<"idle" | "sending" | "success" | "error">("idle");
  const [message, setMessage] = useState<string>("");
  const formRef = useRef<HTMLFormElement>(null);
  const turnstileRef = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);

  // Render Turnstile on mount
  useEffect(() => {
    // On mount or when modal is opened
    if (
      typeof window !== "undefined" &&
      window.turnstile &&
      turnstileRef.current &&
      !turnstileRef.current.hasChildNodes()
    ) {
      // Only render if not already rendered
      widgetId.current = window.turnstile.render(turnstileRef.current, {
        sitekey: turnstileSiteKey,
      });
    }
    // Optionally: reset on unmount (helps with stale widgets in some cases)
    return () => {
      if (typeof window !== "undefined" && window.turnstile && widgetId.current !== null) {
        window.turnstile.reset();
        // Optionally clear widget node, but reset is usually enough
      }
    };
  }, [turnstileSiteKey]);

  // Reset Turnstile after submit/error/success
  const resetTurnstile = () => {
    if (typeof window !== "undefined" && window.turnstile) {
      window.turnstile.reset();
    }
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setStatus("sending");
    setMessage("Sending...");

    if (!formRef.current) return;

    const formData = new FormData(formRef.current);
    const data = Object.fromEntries(formData.entries());

    if (!data["cf-turnstile-response"]) {
      setStatus("error");
      setMessage("Please complete the CAPTCHA.");
      resetTurnstile();
      return;
    }

    try {
      const res = await fetch(workerEndpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      });

      const txt = await res.text();
      if (res.ok) {
        setStatus("success");
        setMessage("Your message has been sent!");
        formRef.current.reset();
        resetTurnstile();
      } else {
        setStatus("error");
        setMessage(
          txt?.toLowerCase().includes("captcha")
            ? "Please verify you are human."
            : "Failed to send. Please try again."
        );
        console.error("Contact form error:", txt);
        resetTurnstile();
      }
    } catch {
      setStatus("error");
      setMessage("Network error. Please try again.");
      resetTurnstile();
    }
  };

  return (
    <>
      <Head>
        <script
          src="https://challenges.cloudflare.com/turnstile/v0/api.js"
          async
          defer
        ></script>
      </Head>
      <div className="mx-auto max-w-2xl p-8 dark:bg-gray-800">
        <h2 className="text-2xl font-semibold mb-6 text-gray-900 dark:text-white">
          Contact Us
        </h2>
        <form ref={formRef} className="space-y-4" onSubmit={handleSubmit} autoComplete="off">
          <input
            type="text"
            name="name"
            placeholder="Your Name"
            required
            className="w-full rounded-md border border-gray-300 p-2"
            autoComplete="off"
          />
          <input
            type="email"
            name="email"
            placeholder="Your Email"
            required
            className="w-full rounded-md border border-gray-300 p-2"
            autoComplete="off"
          />
          <textarea
            name="message"
            placeholder="Your Message"
            required
            rows={4}
            className="w-full rounded-md border border-gray-300 p-2"
          ></textarea>
          {/* Use a ref for Turnstile */}
          <div ref={turnstileRef} className="cf-turnstile" data-sitekey={turnstileSiteKey}></div>
          <button
            type="submit"
            disabled={status === "sending"}
            className="rounded-md bg-indigo-600 px-3.5 py-2.5 text-sm font-semibold !text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
            {status === "sending" ? "Sending..." : "Send"}
          </button>
        </form>
        {message && (
          <p
            className={`mt-4 text-sm ${
              status === "success"
                ? "text-green-700 dark:text-green-400"
                : status === "error"
                ? "text-red-600 dark:text-red-400"
                : "text-gray-600 dark:text-gray-300"
            }`}
          >
            {message}
          </p>
        )}
      </div>
    </>
  );
};

export default ContactForm;
