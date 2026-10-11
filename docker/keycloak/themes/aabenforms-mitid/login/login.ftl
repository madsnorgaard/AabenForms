<#import "template.ftl" as layout>
<#-- DKFDS-styled mock MitID login (Keycloak 23 template contract).
     login_hint is only a username suggestion: when Keycloak prefills the
     username from it we show it read-only, but the person must still type
     the password. "Ikke dig?" lets another username be typed. -->
<#assign hinted = (login.username!'')?has_content && !messagesPerField.existsError('username','password') && !(usernameHidden??)>
<@layout.registrationLayout displayMessage=!messagesPerField.existsError('username','password') displayInfo=false; section>
    <#if section = "header">
        ${msg("loginAccountTitle")}
    <#elseif section = "form">
        <div class="alert alert-warning" role="note">
            <div class="alert-body">
                <p class="alert-heading">${msg("aabenformsDemoBannerTitle")}</p>
                <p class="alert-text">${msg("aabenformsDemoBannerBody")}</p>
            </div>
        </div>

        <#if realm.password>
            <form id="kc-form-login" onsubmit="login.disabled = true; return true;" action="${url.loginAction}" method="post" novalidate>
                <#if !usernameHidden??>
                    <#if hinted>
                        <#-- Keycloak's restart keeps the login_hint client note, so
                             "Ikke dig?" reveals the editable username field instead
                             (pure CSS :target, no JS). -->
                        <div class="form-group af-hinted-wrap">
                            <div class="af-hinted" id="kc-hinted-user">
                                <p class="af-hinted-text">${msg("aabenformsLoggingInAs")} <strong id="kc-hinted-username">${login.username}</strong></p>
                                <a id="kc-restart-login" class="af-link" href="#af-change-user">${msg("aabenformsNotYou")}</a>
                            </div>
                            <div class="af-change-user" id="af-change-user">
                                <label for="username" class="form-label">${msg("username")}</label>
                                <input id="username" class="form-input" name="username" value="${login.username}" type="text"
                                       autocomplete="username" autocapitalize="none" spellcheck="false" />
                            </div>
                        </div>
                    <#else>
                        <div class="form-group<#if messagesPerField.existsError('username','password')> form-error</#if>">
                            <label for="username" class="form-label">${msg("username")}</label>
                            <#if messagesPerField.existsError('username','password')>
                                <span id="input-error" class="form-error-message" aria-live="polite">
                                    <span class="sr-only">${msg("aabenformsErrorPrefix")}</span>
                                    ${kcSanitize(messagesPerField.getFirstError('username','password'))?no_esc}
                                </span>
                            </#if>
                            <input id="username" class="form-input" name="username" value="${(login.username!'')}" type="text"
                                   autofocus autocomplete="username" autocapitalize="none" spellcheck="false"
                                   <#if messagesPerField.existsError('username','password')>aria-invalid="true" aria-describedby="input-error"</#if> />
                        </div>
                    </#if>
                </#if>

                <div class="form-group">
                    <label for="password" class="form-label">${msg("password")}</label>
                    <input id="password" class="form-input" name="password" type="password" autocomplete="current-password"
                           <#if hinted>autofocus</#if>
                           <#if messagesPerField.existsError('username','password')>aria-invalid="true" aria-describedby="input-error"</#if> />
                </div>

                <p class="form-hint af-demo-hint">${msg("aabenformsCredentialHint")}</p>

                <input type="hidden" id="id-hidden-input" name="credentialId" <#if auth.selectedCredential?has_content>value="${auth.selectedCredential}"</#if>/>

                <div id="kc-form-buttons" class="af-actions">
                    <button class="button button-primary" name="login" id="kc-login" type="submit">${msg("doLogIn")}</button>
                </div>
            </form>
        </#if>

        <div class="af-foot">
            <p>${msg("aabenformsFooterReturn")}</p>
            <p>${msg("aabenformsFooterLicense")}</p>
        </div>
    </#if>
</@layout.registrationLayout>
