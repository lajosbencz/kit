FROM alpine:3.18

ARG USER=git
ARG GROUP=git
ARG PASSWORD="12345"
ARG PATH_KIT=/var/kit
ARG PATH_HOSTK=${PATH_KIT}/hostkeys
ARG PATH_AUTHK=${PATH_KIT}/authkeys
ARG PATH_PVC=${PATH_KIT}/pvc
ARG PATH_REPO=${PATH_PVC}/kit.git

ENV PATH_KIT=${PATH_KIT}
ENV PATH_HOSTK=${PATH_HOSTK}
ENV PATH_AUTHK=${PATH_AUTHK}
ENV PATH_PVC=${PATH_PVC}
ENV PATH_REPO=${PATH_REPO}

ENV KUBECONFIG=${PATH_KIT}/kubeconfig

WORKDIR /root

RUN set -ex; \
    mkdir -p ${PATH_HOSTK} \
             ${PATH_AUTHK} \
             ${PATH_REPO}; \
    apk add --no-cache \
        openssl \
        php82 \
        multirun \
        curl \
        git \
        openssh; \
    ln -s /usr/bin/php82 /usr/bin/php; \
    rm /etc/motd

RUN set -ex; \
    curl -LO "https://dl.k8s.io/release/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl"; \
    chmod +x kubectl; \
    mv kubectl /usr/local/bin/; \
    curl -sL https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3 | sh; \
    \
    addgroup "${GROUP}"; \
    adduser \
        --gecos "Kubernetes Infrastructure Kit" \
        --ingroup "${GROUP}" \
        --home "${PATH_PVC}" \
        --disabled-password \
        --shell "$(which git-shell)" \
        "${USER}" ; \
    echo "${USER}:${PASSWORD}" | chpasswd; \
    echo -e "\
Port 2222\n\
HostKey ${PATH_HOSTK}/ssh_host_rsa_key\n\
HostKey ${PATH_HOSTK}/ssh_host_ecdsa_key\n\
HostKey ${PATH_HOSTK}/ssh_host_ed25519_key\n\
PasswordAuthentication no\n\
Match user git\n\
   AuthorizedKeysFile ${PATH_AUTHK}/authorized_keys\n\
" \
    >> /etc/ssh/sshd_config

RUN ln -s ${PATH_KIT}/scripts/post-receive ${PATH_REPO}/hooks/post-receive

COPY ./scripts ${PATH_KIT}/scripts
COPY --chmod=600 ./scripts/kubeconfig ${PATH_KIT}/

RUN chown -R ${USER}:${GROUP} ${PATH_KIT}

EXPOSE 22

VOLUME "${PATH_HOSTK}"
VOLUME "${PATH_AUTHK}"
VOLUME "${PATH_PVC}"

CMD [ "multirun", "${PATH_KIT}/scripts/cmd.sh" ]
