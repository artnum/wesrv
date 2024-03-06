#include "include/msg.h"
#include <cstddef>
#include <cstdint>
#include <stdexcept>
#include <string.h>
#include <iostream>
#include <openssl/evp.h>
#include <openssl/params.h>

namespace wesrv {
    using namespace std;

    Msg::Msg(string key, const unsigned char * data, size_t len) {
        if (len < 2 || len == 0 || len < 23) {
            throw runtime_error("len < 2");
        }
        size_t sentLen = 0;
        sentLen = data[0];
        sentLen = (sentLen << 8) | data[1];
        if (sentLen != len) {
            cout << "sentLen: " << sentLen << " len: " << len << endl;
            throw runtime_error("sentLen != len");
        }
        this->digest = string((const char *)data + 2, 20);
        this->content = string((const char *)data + 22, sentLen - 22);
        if (!this->verify(key)) {
            throw runtime_error("verify failed");
        }
    }

    Msg::Msg(string key, string content) {
        this->content = content;
        this->digest = this->sign(key);
    }

    string Msg::getContent() {
        return this->content;
    }

    string Msg::packet () {
        size_t len = this->content.length() + 22;
        cout << "len: " << len << endl;
        unsigned char * data = new unsigned char[len];
        data[0] = (len >> 8) & 0xFF;
        data[1] = len & 0xFF;
        memcpy(data + 2, this->digest.c_str(), 20);
        memcpy(data + 22, this->content.c_str(), this->content.length());
        string result = string((const char *)data, len);
        delete[] data;
        return result;
    }

    string Msg::sign (string key) {
        EVP_MAC * mac = EVP_MAC_fetch(NULL, "HMAC", NULL);
        EVP_MAC_CTX * ctx = EVP_MAC_CTX_new(mac);
        OSSL_PARAM params[2];
        unsigned char result[20];
        size_t resultLen = 20;

        if (mac == NULL || ctx == NULL) {
            throw runtime_error("mac or ctx is null");
        }

        params[0] = OSSL_PARAM_construct_utf8_string("digest", (char *)"sha1", 0);
        params[1] = OSSL_PARAM_construct_end();

        if (!EVP_MAC_init(ctx, (const unsigned char *)key.c_str(), key.length(), params)) {
            throw runtime_error("EVP_MAC_init failed");
        }
        if (!EVP_MAC_update(ctx, (unsigned char *)this->content.c_str(), this->content.length())) {
            throw runtime_error("EVP_MAC_update failed");
        }
        
        EVP_MAC_final(ctx, result, &resultLen, resultLen);
        EVP_MAC_free(mac);
        EVP_MAC_CTX_free(ctx);

        return string((const char *)result, resultLen);
    }

    bool Msg::verify (string key) {
        string result = this->sign(key);
        return result == this->digest;
    }

    Msg::~Msg() { }
}
