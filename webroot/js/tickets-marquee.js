document.addEventListener('DOMContentLoaded', function () {
    if (typeof MarqueeText !== 'undefined') {
        MarqueeText.init('.ticket-subject-container', '.ticket-subject-text', {
            speed: 60,
            minDuration: 10,
            hoverDelay: 0,
            resetOnLeave: true,
        });
    }
});
