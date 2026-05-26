console.log("STIRFR Welcome JS loaded");

(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {

        /* Progress bar animation */
        const bar = document.querySelector(".stirfr-meter-bar");
        if (bar) {
            requestAnimationFrame(() => {
                const to = parseInt(bar.getAttribute("data-to") || "100", 10);
                bar.style.width = Math.min(to, 100) + "%";
            });
        }

        /* Party burst */
        const hero = document.querySelector(".stirfr-hero");
        if (!hero) return;

        function burstAt(x, y) {
            const wrap = document.createElement("div");
            wrap.style.position = "absolute";
            wrap.style.left = x + "px";
            wrap.style.top = y + "px";
            wrap.style.pointerEvents = "none";
            wrap.style.fontSize = "22px";
            wrap.style.zIndex = "999";

            const pieces = ["✨", "🔹", "🟢", "🟣", "🟡"];

            for (let i = 0; i < 12; i++) {
                const s = document.createElement("span");
                s.textContent = pieces[i % pieces.length];
                s.style.position = "absolute";
                s.style.opacity = "0";
                s.style.transform = "translate(0,0) scale(0.6)";
                s.style.transition =
                    "transform 600ms cubic-bezier(.22,1,.36,1), opacity 600ms ease";

                wrap.appendChild(s);

                setTimeout(() => {
                    const angle = (Math.PI * 2 / 12) * i;
                    const dist = 40 + Math.random() * 30;
                    s.style.transform =
                        `translate(${Math.cos(angle) * dist}px, ${Math.sin(angle) * dist}px) scale(1)`;
                    s.style.opacity = "1";

                    setTimeout(() => {
                        s.style.opacity = "0";
                    }, 420);
                }, 10 * i);
            }

            hero.appendChild(wrap);
            setTimeout(() => wrap.remove(), 1000);
        }

        setTimeout(() => {
            const rect = hero.getBoundingClientRect();
            burstAt(rect.width - 120, rect.height - 80);
        }, 600);

    });
})();
