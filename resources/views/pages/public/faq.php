<?php
require_once __DIR__ . '/../../../../config/app.php';
$pageTitle = 'Frequently Asked Questions | Barangay Culiat Public Facilities Reservation';
$base = base_path();
ob_start();
?>

<section class="page-section faq-section" id="faq">
    <div class="container px-4 px-lg-5">
        <div class="faq-wrapper">
            <div class="text-center mb-5">
                <h2 class="mt-0">Frequently Asked Questions</h2>
                <hr class="divider" />
                <p class="text-muted mb-0">Find answers to common questions about facility reservations</p>
            </div>

            <div class="faq-container">
            <!-- Getting Started -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-rocket-takeoff"></i> Getting Started
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="false">
                            <span>Who can reserve LGU facilities?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq1" class="collapse faq-answer">
                            <p>Ang mga rehistradong residente ng Barangay Culiat, Quezon City ang maaaring mag-reserve ng facilities sa pamamagitan ng sistemang ito. Para magsimula, gumawa lang ng account sa pamamagitan ng pagbibigay ng iyong valid na impormasyon, kasama ang iyong address sa loob ng barangay. Kailangan mong i-verify ang iyong identity sa pamamagitan ng pag-upload ng valid na government-issued ID para ma-enable ang auto-approval features.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq2">
                            <span>How do I create an account?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq2" class="collapse faq-answer">
                            <p>I-click ang "Create Account" mula sa homepage o registration page. Punan ang iyong personal na impormasyon kasama ang iyong buong pangalan, email address, mobile number, at address sa loob ng Barangay Culiat. Maaari kang mag-upload ng valid ID habang nagre-register, o gawin ito mamaya mula sa iyong profile. Kapag nakarehistro na, ang iyong account ay agad na aktibo, bagama't kailangan ang ID verification para sa ilang privileges.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq3">
                            <span>What types of IDs are accepted for verification?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq3" class="collapse faq-answer">
                            <p>Tanggap ang anumang government-issued identification document, kasama ngunit hindi limitado sa: Birth Certificate, Barangay ID, Resident ID, Driver's License, National ID, Passport, Postal ID, o anumang ibang valid na government-issued identification. Ang ID ay dapat malinaw, valid, at ipakita ang iyong buong pangalan na tumutugma sa iyong registration.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking & Reservations -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-calendar-check"></i> Booking & Reservations
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq4">
                            <span>How far in advance can I book?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq4" class="collapse faq-answer">
                            <p>Maaari kang mag-book ng facilities hanggang 30 araw nang maaga. Ang same-day bookings ay maaaring available depende sa availability ng facility, ngunit subject ito sa immediate approval. Inirerekomenda naming mag-book ng hindi bababa sa 3-5 araw nang maaga para matiyak ang iyong preferred date at time slot.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq5">
                            <span>Is approval required for all reservations?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq5" class="collapse faq-answer">
                            <p>Karamihan sa reservations ay nangangailangan ng admin/staff approval. Gayunpaman, ang verified users na may valid IDs ay maaaring qualify para sa auto-approval sa eligible facilities kung lahat ng conditions ay natutugunan (facility ay may enabled na auto-approval, walang conflicts, within booking window, etc.). Makakatanggap ka ng notification kapag ang iyong reservation ay approved o kung kailangan ng additional information.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq6">
                            <span>How long does approval take?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq6" class="collapse faq-answer">
                            <p>Ang approval timeframes ay nag-iiba-iba. Ang auto-approved reservations ay confirmed agad. Ang manual approvals ay karaniwang processed sa loob ng 24-48 hours sa business days (Monday-Friday, 8:00 AM - 5:00 PM). Ang reservations na ginawa sa weekends o holidays ay maaaring tumagal nang mas matagal. Makakatanggap ka ng email at in-app notifications kapag nagbago ang status ng iyong reservation.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq7">
                            <span>Are walk-ins allowed?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq7" class="collapse faq-answer">
                            <p>Ang walk-ins ay subject sa availability ng facility sa first-come, first-served basis. Gayunpaman, lubos naming inirerekomenda na mag-book nang maaga sa pamamagitan ng system para matiyak ang iyong preferred date at time. Ang walk-ins ay maaaring tanggihan kung ang facility ay already reserved o undergoing maintenance.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fees & Payments -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-cash-stack"></i> Fees & Payments
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq8">
                            <span>Are there fees for reserving facilities?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq8" class="collapse faq-answer">
                            <p>Ang fees ay nag-iiba-iba depende sa facility at maaaring depende sa factors gaya ng duration, time of day, type of event, at kung ang activity ay commercial. Tingnan ang individual facility details para sa specific rates. Ang ilang facilities ay maaaring mag-offer ng free time slots para sa community events, non-profit activities, o barangay-sanctioned programs. Lahat ng fees at payment instructions ay ibibigay kapag ang reservation ay approved.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq9">
                            <span>When do I need to pay?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq9" class="collapse faq-answer">
                            <p>Ang payment instructions at deadlines ay ibibigay kapag ang iyong reservation ay approved. Karaniwan, ang payment ay required bago ang reservation date. Ang failure to pay sa loob ng specified period ay maaaring result sa cancellation ng iyong reservation without prejudice to future bookings.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cancellations & Changes -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-x-circle"></i> Cancellations & Changes
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq10">
                            <span>What happens if I cancel my reservation?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq10" class="collapse faq-answer">
                            <p>Ang cancellations ay dapat gawin ng hindi bababa sa 24 hours nang maaga sa pamamagitan ng system o sa pamamagitan ng pag-contact sa Facilities Management Office. Ang cancellations na ginawa ng mas mababa sa 24 hours bago ang reserved time ay maaaring subject sa fees o restrictions. Ang refund policies ay nag-iiba-iba depende sa facility at timing ng cancellation. Pakitingnan ang terms habang nagbo-book.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq11">
                            <span>What happens if I don't show up (no-show)?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq11" class="collapse faq-answer">
                            <p>Ang no-shows ay sineseryoso dahil pinipigilan nito ang ibang residents na gamitin ang facilities. Ang repeated no-shows ay maaaring result sa restrictions sa future bookings, kasama ang temporary suspension ng reservation privileges. Kung hindi ka makaka-attend sa iyong reservation, pakicancel agad para bigyang-daan ang iba na gamitin ang facility.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq12">
                            <span>Can I modify my reservation after approval?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq12" class="collapse faq-answer">
                            <p>Ang changes sa approved reservations ay subject sa availability at admin approval. Contact ang Facilities Management Office sa lalong madaling panahon kung kailangan mong baguhin ang iyong reservation. Ang significant changes ay maaaring require cancellation at re-booking, na maaaring makaapekto sa fees o availability.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Policies & Rules -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-shield-check"></i> Policies & Rules
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq13">
                            <span>What activities are prohibited?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq13" class="collapse faq-answer">
                            <p>Ang unauthorized commercial activity, political gatherings without proper clearance, activities na naglalagay sa panganib ang public safety, at anumang use na lumalabag sa local ordinances ay strictly prohibited. Ang damages sa facilities ay charged sa reserving party at maaaring include administrative sanctions. Lahat ng activities ay dapat comply sa barangay regulations at national laws.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq14">
                            <span>What are the penalties for violations?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq14" class="collapse faq-answer">
                            <p>Ang penalties ay depende sa nature at severity ng violation. Maaaring include ang payment for damages, fees, restrictions sa future bookings, temporary o permanent suspension ng reservation privileges, at sa serious cases, administrative sanctions o legal action. Ang LGU ay may right na mag-reassign, reschedule, o tanggihan ang requests para tiyakin ang public safety at service continuity.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq15">
                            <span>How are disputes handled?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq15" class="collapse faq-answer">
                            <p>Ang disputes ay dapat ireport sa Facilities Management Office agad. Ang office ay rereview ang matter at gagawa ng determination based sa facts, terms and conditions, at barangay policies. Ang decisions ay maaaring appealed sa proper channels. Lahat ng communications at decisions ay documented para sa transparency at accountability.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Technical Support -->
            <div class="faq-category">
                <h3 class="category-title">
                    <i class="bi bi-headset"></i> Technical Support
                </h3>
                <div class="faq-list">
                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq16">
                            <span>I forgot my password. How do I reset it?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq16" class="collapse faq-answer">
                            <p>Sa login page, i-click ang "Forgot Password" at ilagay ang iyong registered email address. Makakatanggap ka ng instructions para i-reset ang iyong password. Kung hindi ka makatanggap ng email, tingnan ang iyong spam folder o contact ang Facilities Management Office para sa assistance.</p>
                        </div>
                    </div>

                    <div class="faq-card">
                        <div class="faq-question" data-bs-toggle="collapse" data-bs-target="#faq17">
                            <span>I'm having trouble accessing the system. What should I do?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div id="faq17" class="collapse faq-answer">
                            <p>Tiyakin na gumagamit ka ng supported web browser (Chrome, Firefox, Safari, o Edge) at may stable internet connection. I-clear ang iyong browser cache at cookies, o subukan gamitin ang ibang browser o device. Kung patuloy ang problema, contact ang Facilities Management Office sa business hours para sa technical support.</p>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div class="text-center mt-5">
                <p class="text-muted mb-3">Still have questions?</p>
                <a href="<?= $base; ?>/contact" class="btn btn-primary">
                    <i class="bi bi-envelope"></i> Contact Us
                </a>
            </div>
        </div>
    </div>
</section>

<style>
/* FAQ Section with Glassmorphism Background */
.faq-section {
    background: url("<?= $base; ?>/public/img/cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;
    position: relative;
    padding: 4rem 0;
}

.faq-section::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.2);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    z-index: 0;
}

.faq-wrapper {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 3rem 2rem;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    max-width: 1000px;
    margin: 0 auto;
}

@media (max-width: 767px) {
    .faq-section {
        padding: 2rem 0;
    }
    
    .faq-wrapper {
        padding: 2rem 1.5rem;
        border-radius: 16px;
    }
}

.faq-container {
    max-width: 900px;
    margin: 0 auto;
}

.faq-category {
    margin-bottom: 3rem;
}

.category-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #1e3a5f;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #285ccd;
}

.category-title i {
    font-size: 1.75rem;
    color: #285ccd;
}

.faq-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.faq-card {
    background: #ffffff !important;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.faq-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #285ccd;
}

.faq-question {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    cursor: pointer;
    background: #f8f9fa;
    font-weight: 600;
    color: #1e3a5f;
    transition: all 0.2s ease;
    user-select: none;
}

.faq-question:hover {
    background: #f0f4ff;
    color: #285ccd;
}

.faq-question[aria-expanded="true"] {
    background: #eff6ff;
    color: #285ccd;
}

.faq-question[aria-expanded="true"] i {
    transform: rotate(180deg);
}

.faq-question i {
    transition: transform 0.3s ease;
    color: #285ccd;
    font-size: 1.25rem;
}

.faq-answer {
    padding: 0 1.5rem;
    background: #ffffff;
}

.faq-answer p {
    padding: 1.25rem 0;
    margin: 0;
    color: #4a5568;
    line-height: 1.8;
    font-size: 0.95rem;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .category-title {
        font-size: 1.25rem;
    }
    
    .faq-question {
        padding: 1rem;
        font-size: 0.95rem;
    }
    
    .faq-answer {
        padding: 0 1rem;
    }
    
    .faq-answer p {
        padding: 1rem 0;
        font-size: 0.9rem;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
